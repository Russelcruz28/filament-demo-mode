<?php

namespace DemoMode\Locks;

use Closure;
use DemoMode\Contracts\LockProvider;
use Illuminate\Support\Facades\DB;

/**
 * A session-level advisory lock on the source database: pg_advisory_lock() on PostgreSQL,
 * GET_LOCK() on MySQL/MariaDB. Other drivers fall back to the file lock.
 * PgBouncer in transaction pooling mode does not support session-level locks; use "cache" there.
 */
class DatabaseLock implements LockProvider
{
    private const NAME = 'demo-mode:sandbox';

    public function run(Closure $callback): mixed
    {
        $connection = DB::connection(config('demo-mode.metadata_connection') ?: config('database.default'));

        return match ($connection->getDriverName()) {
            'pgsql' => $this->hold(
                fn () => $connection->select('select pg_advisory_lock(hashtext(?))', [self::NAME]),
                fn () => $connection->select('select pg_advisory_unlock(hashtext(?))', [self::NAME]),
                $callback,
            ),
            'mysql', 'mariadb' => $this->hold(
                fn () => (int) $connection->selectOne('select get_lock(?, 60) as acquired', [self::NAME])->acquired === 1
                    ?: throw new \RuntimeException('Timed out waiting for the demo sandbox lock.'),
                fn () => $connection->select('select release_lock(?)', [self::NAME]),
                $callback,
            ),
            default => (new FileLock)->run($callback),
        };
    }

    private function hold(Closure $acquire, Closure $release, Closure $callback): mixed
    {
        $acquire();
        try {
            return $callback();
        } finally {
            $release();
        }
    }
}
