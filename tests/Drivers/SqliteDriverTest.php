<?php

namespace DemoMode\Tests\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class SqliteDriverTest extends DriverContract
{
    protected static function sourceConfig(): ?array
    {
        return ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/filament-demo-source-'.getmypid().'.sqlite',
            'prefix' => '', 'foreign_key_constraints' => true];
    }

    protected function driverKey(): string
    {
        return 'sqlite';
    }

    protected function resetSource(Connection $source): void
    {
        DB::purge('source');
        file_put_contents(static::sourceConfig()['database'], '');
    }

    protected function insertOrphan(Connection $source): void
    {
        $source->statement('PRAGMA foreign_keys = OFF');
        $source->table('people')->insert(['name' => 'orphan', 'team_id' => 999]);
        $source->statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(static::sourceConfig()['database']);
    }
}
