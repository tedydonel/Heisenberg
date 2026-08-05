<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Persistence;

/**
 * Shared with {@see BlockPersistenceTest}: the default local/CI run uses the
 * in-memory SQLite connection (see TestCase::getEnvironmentSetUp()), on which
 * every test in this namespace runs unmodified. If a host CI opts into MySQL
 * (DB_CONNECTION=mysql) but the server isn't reachable, skip gracefully
 * instead of erroring — same rationale as BlockPersistenceTest, extracted
 * here so the other Persistence test classes added alongside it don't each
 * duplicate the probe.
 */
trait SkipsWhenMysqlUnreachable
{
    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') === 'mysql' && ! self::mysqlIsReachable()) {
            $this->markTestSkipped(sprintf(
                'DB_CONNECTION=mysql but no server is reachable at %s:%s — the default local '
                . 'run uses SQLite (see TestCase::getEnvironmentSetUp()); this skip only fires '
                . 'when MySQL is explicitly requested via phpunit.xml.',
                env('DB_HOST', '127.0.0.1'),
                env('DB_PORT', '3306'),
            ));

            return;
        }

        parent::setUp();
    }

    /** A raw, app-independent reachability probe — safe to call before the app boots. */
    private static function mysqlIsReachable(): bool
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                env('DB_HOST', '127.0.0.1'),
                env('DB_PORT', '3306'),
                env('DB_DATABASE', 'heisenberg_test'),
            );
            new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', ''), [\PDO::ATTR_TIMEOUT => 1]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
