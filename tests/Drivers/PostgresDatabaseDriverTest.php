<?php

namespace DemoMode\Tests\Drivers;

use Illuminate\Database\Connection;
use Symfony\Component\Process\ExecutableFinder;

class PostgresDatabaseDriverTest extends DriverContract
{
    use InteractsWithPostgres;

    protected function driverKey(): string
    {
        return 'pgsql-database';
    }

    protected function resetSource(Connection $source): void
    {
        $this->resetPostgres($source);
    }

    public function test_drifted_columns_fail_with_migrations_and_copy_with_clone(): void
    {
        // Cloning into a separate database goes through pg_dump and psql.
        if ((new ExecutableFinder)->find('pg_dump') === null) {
            $this->markTestSkipped('pg_dump is not installed.');
        }
        parent::test_drifted_columns_fail_with_migrations_and_copy_with_clone();
    }
}
