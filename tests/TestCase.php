<?php

declare(strict_types=1);

namespace Heisenberg\Tests;

use Heisenberg\HeisenbergServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            HeisenbergServiceProvider::class,
        ];
    }

    /**
     * App key (cookie encryption) + the test DB connection.
     *
     * Defaults to an in-memory SQLite connection so `php vendor/bin/phpunit`
     * passes with zero external services on a fresh checkout — the posts/blocks
     * schema (uuid/enum/json/timestamps/softDeletes + a cascading FK) is fully
     * representable on SQLite via Laravel's grammar (enum -> CHECK constraint,
     * FKs via `foreign_key_constraints`). Blueprint Rule 11 (the *full* future
     * magazine schema — taxonomy, revisions, patterns — gets MySQL-only tests
     * once those tables exist) still applies for that later, richer schema; a
     * host CI that wants real MySQL coverage today can still get it by setting
     * DB_CONNECTION=mysql (see phpunit.xml.dist) — BlockPersistenceTest degrades
     * to markTestSkipped() rather than erroring when that's requested but the
     * server isn't reachable.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('h', 32)));

        $connection = env('DB_CONNECTION', 'sqlite');
        $app['config']->set('database.default', $connection);

        if ($connection === 'mysql') {
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'heisenberg_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);

            return;
        }

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
