<?php

namespace DemoMode\Tests\Drivers;

use DemoMode\DemoManager;
use DemoMode\SandboxRuntime;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * DEMO_MODE_TEST_MYSQL_HOST, _PORT, _DATABASE, _USERNAME, _PASSWORD and optionally _DRIVER=mariadb.
 * The user needs CREATE/DROP on any database and permission to create routines and triggers.
 */
class MySqlDatabaseDriverTest extends DriverContract
{
    protected static function sourceConfig(): ?array
    {
        if (! getenv('DEMO_MODE_TEST_MYSQL_HOST')) {
            return null;
        }

        return [
            'driver' => getenv('DEMO_MODE_TEST_MYSQL_DRIVER') ?: 'mysql', 'host' => getenv('DEMO_MODE_TEST_MYSQL_HOST'),
            'port' => getenv('DEMO_MODE_TEST_MYSQL_PORT') ?: 3306, 'database' => getenv('DEMO_MODE_TEST_MYSQL_DATABASE') ?: 'demo_test',
            'username' => getenv('DEMO_MODE_TEST_MYSQL_USERNAME') ?: 'root', 'password' => getenv('DEMO_MODE_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
        ];
    }

    protected function driverKey(): string
    {
        return 'mysql-database';
    }

    protected function resetSource(Connection $source): void
    {
        $database = $source->getDatabaseName();
        foreach ($source->select("select schema_name as name from information_schema.schemata where schema_name regexp '^demo_[0-9a-f]{12}$'") as $sandbox) {
            $source->statement('drop database `'.$sandbox->name.'`');
        }
        $source->statement('drop database if exists `'.$database.'`');
        $source->statement('create database `'.$database.'`');
        $source->statement('use `'.$database.'`');
    }

    protected function insertOrphan(Connection $source): void
    {
        $source->statement('set foreign_key_checks = 0');
        $source->table('people')->insert(['name' => 'orphan', 'team_id' => 999]);
        $source->statement('set foreign_key_checks = 1');
    }

    public function test_triggers_are_recreated_after_the_copy_and_procedures_target_the_sandbox(): void
    {
        $this->seedSource();
        $this->source()->table('people')->where('id', 1)->update(['name_upper' => 'custom']);
        app(DemoManager::class)->start();

        $runtime = app(SandboxRuntime::class);
        $runtime->enter(session('demo_mode.token'));
        try {
            $this->assertSame('custom', DB::table('people')->where('id', 1)->value('name_upper'));
            DB::table('people')->insert(['name' => 'new person']);
            $this->assertSame('NEW PERSON', DB::table('people')->where('name', 'new person')->value('name_upper'));
            DB::statement("call open_ticket('demo')");
            $this->assertSame(1004, (int) DB::table('tickets')->max('number'));
        } finally {
            $runtime->leave();
        }
        $this->assertSame(3, $this->source()->table('tickets')->count());
    }

    public function test_clone_recreates_routines_and_triggers(): void
    {
        $this->seedSource();
        config(['demo-mode.sandbox.schema' => 'clone']);
        app(DemoManager::class)->start();

        $runtime = app(SandboxRuntime::class);
        $runtime->enter(session('demo_mode.token'));
        try {
            DB::statement("call open_ticket('demo')");
            $this->assertSame(4, DB::table('tickets')->count());
            DB::table('people')->insert(['name' => 'cloned trigger']);
            $this->assertSame('CLONED TRIGGER', DB::table('people')->where('name', 'cloned trigger')->value('name_upper'));
        } finally {
            $runtime->leave();
        }
        $this->assertSame(3, $this->source()->table('tickets')->count());
    }

    public function test_pool_databases_are_reused_and_emptied(): void
    {
        foreach (['demo_pool_a', 'demo_pool_b'] as $database) {
            $this->source()->statement("drop database if exists `{$database}`");
            $this->source()->statement("create database `{$database}`");
        }
        config(['demo-mode.sandbox.pool' => ['demo_pool_a', 'demo_pool_b']]);
        $this->seedSource();
        $manager = app(DemoManager::class);
        $manager->start();
        $first = $manager->ref(session('demo_mode.token'))->container;
        $manager->stop();
        $manager->start();
        $second = $manager->ref(session('demo_mode.token'))->container;

        $this->assertNotSame($first, $second);
        $this->assertSame(7, $this->demo()->table('people')->count());
        $this->assertSame([], $this->source()->select('select table_name from information_schema.tables where table_schema = ?', [$first]));
    }
}
