<?php

namespace DemoMode\Tests\Drivers;

use Illuminate\Database\Connection;

/**
 * DEMO_MODE_TEST_PGSQL_HOST, _PORT, _DATABASE, _USERNAME, _PASSWORD. The user must be a superuser:
 * tests create roles and databases and bypass foreign keys to plant orphans.
 */
trait InteractsWithPostgres
{
    protected static function sourceConfig(): ?array
    {
        if (! getenv('DEMO_MODE_TEST_PGSQL_HOST')) {
            return null;
        }

        return [
            'driver' => 'pgsql', 'host' => getenv('DEMO_MODE_TEST_PGSQL_HOST'), 'port' => getenv('DEMO_MODE_TEST_PGSQL_PORT') ?: 5432,
            'database' => getenv('DEMO_MODE_TEST_PGSQL_DATABASE') ?: 'demo_test', 'username' => getenv('DEMO_MODE_TEST_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('DEMO_MODE_TEST_PGSQL_PASSWORD') ?: '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ];
    }

    protected function resetPostgres(Connection $source): void
    {
        $source->unprepared(<<<'SQL'
            drop schema if exists public cascade;
            create schema public;
            grant usage on schema public to public;
            do $$ declare name text; begin
                for name in select nspname from pg_namespace where nspname ~ '^demo_[0-9a-f]{12}$' loop
                    execute format('drop schema %I cascade', name);
                end loop;
            end $$;
        SQL);
        foreach ($source->select("select datname from pg_database where datname ~ '^demo_[0-9a-f]{12}$'") as $database) {
            $source->statement('drop database "'.$database->datname.'" with (force)');
        }
    }

    protected function insertOrphan(Connection $source): void
    {
        $source->transaction(function () use ($source): void {
            $source->statement('set local session_replication_role = replica');
            $source->table('people')->insert(['name' => 'orphan', 'team_id' => 999]);
        });
    }
}
