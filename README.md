# marko/database-readwrite

Routes reads to replicas, writes to primary --- drop-in decorator for any Marko database driver.

## Installation

```bash
composer require marko/database-readwrite
```

## Quick Example

```php title="config/database.php"
<?php

declare(strict_types=1);

use Marko\Config\Env;

return [
    'driver' => 'readwrite',
    'connections' => [
        'write' => [
            'driver'   => 'pgsql',
            'host'     => Env::string('DB_WRITE_HOST', 'localhost'),
            'port'     => Env::int('DB_WRITE_PORT', 5432, min: 1, max: 65535),
            'database' => Env::string('DB_DATABASE', 'marko'),
            'username' => Env::string('DB_USERNAME', 'postgres'),
            'password' => Env::string('DB_PASSWORD', ''),
        ],
        'read' => [
            [
                'driver'   => 'pgsql',
                'host'     => Env::string('DB_READ_HOST', 'replica-1'),
                'port'     => Env::int('DB_READ_PORT', 5432, min: 1, max: 65535),
                'database' => Env::string('DB_DATABASE', 'marko'),
                'username' => Env::string('DB_USERNAME', 'postgres'),
                'password' => Env::string('DB_PASSWORD', ''),
            ],
        ],
        'read_strategy' => 'random',
    ],
];
```

## Documentation

Full usage, configuration, API reference, and examples: [marko/database-readwrite](https://marko.build/docs/packages/database-readwrite/)
