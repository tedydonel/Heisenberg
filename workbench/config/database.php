<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Workbench (demo host) database config
|--------------------------------------------------------------------------
|
| Replaces Testbench's bundled skeleton config/database.php outright (see
| Orchestra\Testbench\Bootstrap\LoadConfiguration::resolveConfigurationFile()
| — workbench.discovers.config: true substitutes this whole file in, not a
| merge) for exactly one reason: `php vendor/bin/testbench serve` launches
| PHP's built-in server with its DOCUMENT ROOT as the worker's cwd
| (vendor/orchestra/testbench-core/laravel/public), NOT this package's root.
| A relative `DB_DATABASE` path (testbench.yaml's `env:` — the natural thing
| to write) therefore resolves against THAT cwd, is_file() on it fails, and
| Orchestra\Testbench\Bootstrap\LoadConfiguration::configureDefaultDatabase
| Connection() silently swaps `database.default` to a fresh `:memory:`
| "testing" connection per request — the demo blog 500s with "no such table"
| even though `migrate:fresh` + `demo:seed` already ran successfully against
| the real file (see docs/demo.md's own report of this exact failure). Using
| __DIR__ here makes the path ABSOLUTE and independent of whatever cwd the
| server happens to run from.
*/

return [

    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE') ?: dirname(__DIR__) . '/database/testbench.sqlite',
            'prefix' => '',
            'prefix_indexes' => null,
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
            'pragmas' => [],
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => 'heisenberg_workbench_database_',
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
    ],

];
