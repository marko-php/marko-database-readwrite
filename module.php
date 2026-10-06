<?php

declare(strict_types=1);

use Marko\Config\ConfigRepositoryInterface;
use Marko\Core\Container\ContainerInterface;
use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Config\DatabaseTimezoneConfig;
use Marko\Database\Connection\ConnectionFactoryInterface;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\TransactionInterface;
use Marko\Database\ReadWrite\Config\ReadWriteConnectionConfig;
use Marko\Database\ReadWrite\Connection\ReadWriteConnection;
use Marko\Database\ReadWrite\Replica\RandomReplicaSelector;
use Marko\Database\ReadWrite\Replica\WeightedReplicaSelector;

return [
    'bindings' => [],
    'boot' => function (ContainerInterface $container): void {
        $config = $container->get(ConfigRepositoryInterface::class);

        if ($config->get('database.driver') !== 'readwrite') {
            return;
        }

        $connections = $config->get('database.connections');
        $rwConfig = ReadWriteConnectionConfig::fromArray(['connections' => $connections]);

        // Every node pins its session to the one top-level database.timezone (the zone DatabaseTimezoneConfig
        // formats stored datetimes in), so the primary and the replicas agree. A per-node timezone is ignored.
        $timezone = $config->has('database.timezone') ? $config->get('database.timezone') : null;
        $nodeConfig = static fn (array $node): DatabaseConfig => DatabaseConfig::fromArray([
            ...$node,
            'timezone' => $timezone ?? DatabaseTimezoneConfig::DEFAULT_TIMEZONE,
        ]);

        $factory = $container->get(ConnectionFactoryInterface::class);
        $writeConnection = $factory->make($nodeConfig($rwConfig->write));

        $replicaConnections = array_map(
            fn (array $replicaConfig) => $factory->make($nodeConfig($replicaConfig)),
            $rwConfig->reads,
        );

        $selector = match ($rwConfig->readStrategy) {
            'weighted' => new WeightedReplicaSelector(
                array_map(fn (array $r) => $r['weight'] ?? 1, $rwConfig->reads),
            ),
            default => new RandomReplicaSelector(),
        };

        $readWriteConnection = new ReadWriteConnection($writeConnection, $replicaConnections, $selector);
        $container->instance(ConnectionInterface::class, $readWriteConnection);
        $container->instance(TransactionInterface::class, $readWriteConnection);
    },
];
