<?php

namespace DemoMode\Tests\Drivers;

use DemoMode\DemoManager;
use DemoMode\ProvisioningFailed;
use DemoMode\SandboxRuntime;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PostgresSchemaDriverTest extends DriverContract
{
    use InteractsWithPostgres;

    protected function driverKey(): string
    {
        return 'pgsql-schema';
    }

    public function test_functions_and_triggers_target_the_sandbox(): void
    {
        $this->seedSource();
        $this->source()->table('people')->where('id', 1)->update(['name_upper' => 'custom']);
        $lastTicket = $this->source()->table('tickets')->max('number');
        app(DemoManager::class)->start();

        $runtime = app(SandboxRuntime::class);
        $runtime->enter(session('demo_mode.token'));
        try {
            // Triggers were disabled during the copy and run again afterwards.
            $this->assertSame('custom', DB::table('people')->where('id', 1)->value('name_upper'));
            DB::table('people')->insert(['name' => 'new person']);
            $this->assertSame('NEW PERSON', DB::table('people')->where('name', 'new person')->value('name_upper'));
            // The function resolves tables and sequences in the sandbox schema.
            $number = DB::selectOne("select open_ticket('demo') as number")->number;
            $this->assertGreaterThan($lastTicket, $number);
            $this->assertSame(4, DB::table('tickets')->count());
        } finally {
            $runtime->leave();
        }
        $this->assertSame(3, $this->source()->table('tickets')->count());
        $this->assertSame($lastTicket, (int) $this->source()->selectOne('select last_value from ticket_numbers')->last_value);
    }

    public function test_clone_recreates_functions_sequences_and_triggers(): void
    {
        $this->seedSource();
        config(['demo-mode.sandbox.schema' => 'clone']);
        app(DemoManager::class)->start();

        $runtime = app(SandboxRuntime::class);
        $runtime->enter(session('demo_mode.token'));
        try {
            $this->assertSame(1003, (int) DB::selectOne("select open_ticket('demo') as number")->number);
            DB::table('people')->insert(['name' => 'cloned trigger']);
            $this->assertSame('CLONED TRIGGER', DB::table('people')->where('name', 'cloned trigger')->value('name_upper'));
        } finally {
            $runtime->leave();
        }
        $this->assertSame(3, $this->source()->table('tickets')->count());
    }

    public function test_clone_refuses_functions_that_name_the_production_schema(): void
    {
        $this->source()->unprepared('create function count_people() returns bigint language sql as $$ select count(*) from public.people $$');
        config(['demo-mode.sandbox.schema' => 'clone']);

        $this->assertStringContainsString('count_people', $this->provisionUntilFailure()->getMessage());
    }

    public function test_dedicated_role_turns_fall_through_into_an_error(): void
    {
        $this->source()->unprepared(<<<'SQL'
            do $$ begin
                if not exists (select 1 from pg_roles where rolname = 'demo_sandbox') then
                    create role demo_sandbox login password 'secret';
                end if;
            end $$;
        SQL);
        $this->source()->statement('grant create on database '.$this->source()->getDatabaseName().' to demo_sandbox');
        config(['database.connections.sandbox_role' => [...static::sourceConfig(), 'username' => 'demo_sandbox', 'password' => 'secret'],
            'demo-mode.sandbox.connection' => 'sandbox_role']);
        $this->seedSource();
        app(DemoManager::class)->start();
        $this->assertSame(7, $this->demo()->table('people')->count());
        // A production table the sandbox does not have: unqualified queries would fall through the search_path.
        $this->source()->statement('create table secrets (value text)');
        $this->source()->table('secrets')->insert(['value' => 'production only']);

        $runtime = app(SandboxRuntime::class);
        $runtime->enter(session('demo_mode.token'));
        try {
            DB::table('secrets')->count();
            $this->fail('The sandbox role read a production table.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0]);
        } finally {
            $runtime->leave();
        }
    }

    public function test_production_requires_a_dedicated_role(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(ProvisioningFailed::class);
        $this->expectExceptionMessage('dedicated sandbox role');

        app(DemoManager::class)->begin();
    }

    protected function resetSource(Connection $source): void
    {
        $this->resetPostgres($source);
        $source->unprepared(<<<'SQL'
            do $$ begin
                if exists (select 1 from pg_roles where rolname = 'demo_sandbox') then
                    execute 'drop owned by demo_sandbox cascade';
                end if;
            end $$;
        SQL);
    }
}
