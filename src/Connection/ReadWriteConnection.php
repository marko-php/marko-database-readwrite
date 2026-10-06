<?php

declare(strict_types=1);

namespace Marko\Database\ReadWrite\Connection;

use Closure;
use Marko\Core\Contracts\ResettableInterface;
use Marko\Core\Exceptions\MarkoException;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\PendingAfterCommitInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Database\Connection\TransactionInterface;
use Marko\Database\Exceptions\TransactionConflictException;
use Marko\Database\Exceptions\TransactionException;
use Marko\Database\ReadWrite\Exceptions\ReadException;
use Marko\Database\ReadWrite\Replica\ReplicaSelectorInterface;
use Override;
use PDOException;

class ReadWriteConnection implements ConnectionInterface, TransactionInterface, PendingAfterCommitInterface, ResettableInterface
{
    private bool $stickyWrite = false;

    /**
     * @param ConnectionInterface[] $replicas
     */
    public function __construct(
        private ConnectionInterface&TransactionInterface $write,
        private array $replicas,
        private ReplicaSelectorInterface $replicaSelector,
    ) {}

    /**
     * @throws ReadException
     */
    public function query(
        string $sql,
        array $bindings = [],
    ): array {
        if ($this->isWriteStatement($sql)) {
            // A write that returns rows (INSERT ... RETURNING) sticks to the
            // write connection like execute() does, so reads see it.
            $this->stickyWrite = true;
        }

        if ($this->stickyWrite) {
            return $this->write->query($sql, $bindings);
        }

        // Try each replica in turn, falling back on PDOException or MarkoException.
        // NOTE: WeightedReplicaSelector weights are based on original indices; they
        // do not rebalance when replicas are removed during fallback (known v1 limitation).
        $remaining = $this->replicas;
        $failures = [];

        while ($remaining !== []) {
            $replica = $this->replicaSelector->select($remaining);

            try {
                return $replica->query($sql, $bindings);
            } catch (PDOException $e) {
                $failures[] = $e->getMessage();
                $remaining = array_values(array_filter($remaining, fn ($r) => $r !== $replica));
            } catch (MarkoException $e) {
                $failures[] = $e->getMessage();
                $remaining = array_values(array_filter($remaining, fn ($r) => $r !== $replica));
            }
        }

        throw ReadException::allReplicasFailed($failures);
    }

    public function execute(
        string $sql,
        array $bindings = [],
    ): int {
        $this->stickyWrite = true;

        return $this->write->execute($sql, $bindings);
    }

    public function prepare(
        string $sql,
    ): StatementInterface {
        return $this->write->prepare($sql);
    }

    public function lastInsertId(): int
    {
        return $this->write->lastInsertId();
    }

    public function driverName(): string
    {
        return $this->write->driverName();
    }

    public function supportsReturning(): bool
    {
        return $this->write->supportsReturning();
    }

    public function quoteIdentifier(
        string $identifier,
    ): string {
        return $this->write->quoteIdentifier($identifier);
    }

    public function connect(): void
    {
        $this->write->connect();
    }

    public function disconnect(): void
    {
        $this->write->disconnect();
    }

    public function isConnected(): bool
    {
        return $this->write->isConnected();
    }

    public function beginTransaction(): void
    {
        $this->stickyWrite = true;
        $this->write->beginTransaction();
    }

    public function commit(): void
    {
        $this->write->commit();
    }

    public function rollback(): void
    {
        $this->write->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->write->inTransaction();
    }

    public function transactionLevel(): int
    {
        return $this->write->transactionLevel();
    }

    /**
     * Routes every read inside the callback to the write connection, then
     * restores the sticky flag it had before the call. A nested transaction()
     * therefore leaves the outer transaction's reads on the write connection.
     *
     * $attempts and $backoff are passed to the write connection, which owns
     * the retry and the wait between attempts.
     *
     * @throws TransactionException|TransactionConflictException When $attempts is below 1, $backoff is
     *     negative, or the last attempt still conflicts
     */
    public function transaction(
        callable $callback,
        int $attempts = 1,
        int|Closure|null $backoff = null,
    ): mixed {
        $wasSticky = $this->stickyWrite;
        $this->stickyWrite = true;

        try {
            return $this->write->transaction($callback, $attempts, $backoff);
        } finally {
            $this->stickyWrite = $wasSticky;
        }
    }

    public function afterCommit(
        callable $callback,
    ): void {
        $this->write->afterCommit($callback);
    }

    public function afterRollback(
        callable $callback,
    ): void {
        $this->write->afterRollback($callback);
    }

    /**
     * @throws TransactionException When the write connection cannot run pending callbacks
     */
    public function runPendingAfterCommitCallbacks(): void
    {
        if (!$this->write instanceof PendingAfterCommitInterface) {
            throw TransactionException::cannotRunPendingAfterCommitCallbacks($this->write::class);
        }

        $this->write->runPendingAfterCommitCallbacks();
    }

    public function resetStickyState(): void
    {
        $this->stickyWrite = false;
    }

    /**
     * Rolls back a transaction abandoned by a request that threw before
     * commit()/rollback(), then clears the sticky-write flag.
     *
     * A resettable write connection resets itself (rolling back every level
     * and dropping pending callbacks). Otherwise every open level is rolled
     * back, innermost first, so no savepoint leaves the outer transaction open.
     *
     * The rollback runs first (inside try) and the sticky-state reset
     * runs in finally so it always happens, even if the rollback itself
     * throws. The exception is intentionally not swallowed here: a
     * failed rollback means the pooled connection may still be in an
     * unknown transactional state, and the caller needs to know.
     */
    #[Override]
    public function reset(): void
    {
        try {
            if ($this->write instanceof ResettableInterface) {
                $this->write->reset();
            } else {
                for ($level = $this->write->transactionLevel(); $level > 0; $level--) {
                    $this->write->rollback();
                }
            }
        } finally {
            $this->resetStickyState();
        }
    }

    /**
     * Detects whether a SQL statement is a write operation (INSERT, UPDATE, DELETE).
     *
     * Leading whitespace and a leading SQL line comment (-- ...) or block comment
     * (/* ... *\/) are stripped before sniffing the first keyword, case-insensitively.
     *
     * NOTE (v1 limitation): CTEs — a leading WITH clause whose final DML is INSERT/
     * UPDATE/DELETE — are NOT detected here and will route to a replica. Use execute()
     * or beginTransaction()/commit() for write CTEs, or call resetStickyState() after
     * routing to ensure correct behaviour. With ... INSERT ... RETURNING should use
     * execute() instead.
     */
    private function isWriteStatement(
        string $sql,
    ): bool {
        $trimmed = ltrim($sql);

        // Strip a leading line comment: -- ...
        if (str_starts_with($trimmed, '--')) {
            $trimmed = ltrim(substr($trimmed, (int) strpos($trimmed, "\n") + 1));
        }

        // Strip a leading block comment: /* ... */
        if (str_starts_with($trimmed, '/*')) {
            $end = strpos($trimmed, '*/');
            $trimmed = $end !== false ? ltrim(substr($trimmed, $end + 2)) : $trimmed;
        }

        return (bool) preg_match('/^(INSERT|UPDATE|DELETE)\b/i', $trimmed);
    }
}
