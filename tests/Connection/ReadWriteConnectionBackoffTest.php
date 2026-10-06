<?php

declare(strict_types=1);

use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Connection\TransactionBackoff;
use Marko\Database\Exceptions\DeadlockException;
use Marko\Database\Exceptions\TransactionConflictException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\ReadWrite\Connection\ReadWriteConnection;
use Marko\Database\ReadWrite\Replica\ReplicaSelectorInterface;
use Marko\Testing\Fake\FakeSleeper;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A ReadWriteConnection whose write and replica connections are MySqlConnections
 * over in-memory SQLite PDOs, so transaction() runs the real driver retry loop.
 */
function makeBackoffReadWriteConnection(
    TransactionBackoff $backoff,
): ReadWriteConnection {
    $config = DatabaseConfig::fromArray([
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'test',
        'username' => 'test',
        'password' => 'test',
    ]);

    $makeConnection = fn (): MySqlConnection => new class ($config, $backoff) extends MySqlConnection
    {
        private readonly PDO $memoryPdo;

        public function __construct(
            DatabaseConfig $config,
            TransactionBackoff $backoff,
        ) {
            parent::__construct($config, transactionBackoff: $backoff);
            $this->memoryPdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }

        protected function createPdo(
            string $dsn,
            string $username,
            string $password,
            array $options,
        ): PDO {
            return $this->memoryPdo;
        }
    };

    $replica = $makeConnection();

    return new ReadWriteConnection(
        $makeConnection(),
        [$replica],
        new class ($replica) implements ReplicaSelectorInterface
        {
            public function __construct(
                private readonly MySqlConnection $replica,
            ) {}

            public function select(
                array $replicas,
            ): MySqlConnection {
                return $this->replica;
            }
        },
    );
}

function readWriteDeadlock(): DeadlockException
{
    return DeadlockException::fromDriverError(
        new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'),
        'UPDATE items SET name = ?',
        [],
    );
}

/**
 * Runs a transaction whose first $conflicts attempts fail with a deadlock.
 */
function runReadWriteConflictingTransaction(
    ReadWriteConnection $connection,
    int $conflicts,
    int $attempts,
    int|Closure|null $backoff = null,
): int {
    $calls = 0;

    return $connection->transaction(function () use (&$calls, $conflicts): int {
        if (++$calls <= $conflicts) {
            throw readWriteDeadlock();
        }

        return $calls;
    }, attempts: $attempts, backoff: $backoff);
}

describe('ReadWriteConnection::transaction() backoff', function (): void {
    it('waits the default jittered delay between attempts through the write connection', function (): void {
        $sleeper = new FakeSleeper();
        $connection = makeBackoffReadWriteConnection(
            new TransactionBackoff($sleeper, new Randomizer(new Mt19937(5))),
        );
        $reference = new Randomizer(new Mt19937(5));

        $result = runReadWriteConflictingTransaction($connection, conflicts: 2, attempts: 3);

        expect($result)->toBe(3)
            ->and($sleeper->sleeps)->toBe([$reference->getInt(0, 10), $reference->getInt(0, 20)]);
    });

    it('retries immediately through the write connection when backoff is zero', function (): void {
        $sleeper = new FakeSleeper();
        $connection = makeBackoffReadWriteConnection(new TransactionBackoff($sleeper));

        $result = runReadWriteConflictingTransaction($connection, conflicts: 2, attempts: 3, backoff: 0);

        expect($result)->toBe(3)
            ->and($sleeper->sleeps)->toBe([0, 0]);
    });

    it('honours an int and a closure backoff through the write connection', function (): void {
        $sleeper = new FakeSleeper();
        $connection = makeBackoffReadWriteConnection(new TransactionBackoff($sleeper));

        runReadWriteConflictingTransaction($connection, conflicts: 1, attempts: 2, backoff: 45);
        runReadWriteConflictingTransaction(
            $connection,
            conflicts: 2,
            attempts: 3,
            backoff: fn (int $attempt, TransactionConflictException $conflict): int => $attempt * 7,
        );

        expect($sleeper->sleeps)->toBe([45, 7, 14]);
    });

    it('never sleeps in a nested transaction through the write connection', function (): void {
        $sleeper = new FakeSleeper();
        $connection = makeBackoffReadWriteConnection(new TransactionBackoff($sleeper));
        $innerSleeps = null;

        $connection->transaction(function () use ($connection, $sleeper, &$innerSleeps): void {
            try {
                runReadWriteConflictingTransaction($connection, conflicts: 1, attempts: 4, backoff: 30);
            } catch (DeadlockException) {
                $innerSleeps = $sleeper->sleeps;
            }
        }, attempts: 2, backoff: 30);

        expect($innerSleeps)->toBe([])
            ->and($sleeper->sleeps)->toBe([]);
    });

    it('never sleeps when attempts is one through the write connection', function (): void {
        $sleeper = new FakeSleeper();
        $connection = makeBackoffReadWriteConnection(new TransactionBackoff($sleeper));

        expect(fn () => runReadWriteConflictingTransaction($connection, conflicts: 1, attempts: 1, backoff: 30))
            ->toThrow(DeadlockException::class)
            ->and($sleeper->sleeps)->toBe([]);
    });
});
