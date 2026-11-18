<?php

namespace DemoMode\Tests\Drivers;

use DemoMode\Contracts\SandboxDriver;
use DemoMode\DemoManager;
use DemoMode\DemoModeOptions;
use DemoMode\ProvisioningFailed;
use DemoMode\Sandbox\SandboxDrivers;
use DemoMode\Sandbox\TablePlan;
use DemoMode\SandboxRuntime;
use DemoMode\Tests\Fixtures\User;
use DemoMode\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Every sandbox driver must pass this suite. The source connection is named "source".
 */
abstract class DriverContract extends TestCase
{
    /** Connection config for the source database, or null to skip (server not configured). */
    abstract protected static function sourceConfig(): ?array;

    abstract protected function driverKey(): string;

    /** Drop everything in the source database and every sandbox container. */
    abstract protected function resetSource(Connection $source): void;

    /** Insert a row whose foreign key points nowhere, bypassing the source's own checks. */
    abstract protected function insertOrphan(Connection $source): void;

    protected function setUp(): void
    {
        if (static::sourceConfig() === null) {
            $this->markTestSkipped('Set the '.static::class.' database environment variables to run this driver suite.');
        }
        parent::setUp();
        $this->actingAs(User::create(['name' => 'Presenter']));
        app(DemoModeOptions::class)->authorize = fn () => true;
        app(DemoModeOptions::class)->prepare = fn () => null;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.source', static::sourceConfig());
        $app['config']->set('database.default', 'source');
        $app['config']->set('session.connection', 'source');
        $app['config']->set('demo-mode.sandbox.driver', $this->driverKey());
        $app['config']->set('demo-mode.required_tables', ['teams', 'people', 'memberships', 'logs', 'tickets']);
        $app['config']->set('demo-mode.provisioning', ['chunk_size' => 2, 'step_seconds' => 0]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->resetSource(DB::connection('source'));
        DB::purge('source');
        foreach ([__DIR__.'/../../database/migrations', __DIR__.'/../Fixtures/migrations', __DIR__.'/../Fixtures/driver-migrations'] as $path) {
            $this->app['migrator']->path($path);
        }
        $this->artisan('migrate', ['--database' => 'source'])->run();
    }

    protected function source(): Connection
    {
        return DB::connection('source');
    }

    protected function driver(): SandboxDriver
    {
        return app(SandboxDrivers::class)->make($this->driverKey());
    }

    protected function demo(?string $token = null): Connection
    {
        app(DemoManager::class)->configure($token ?? session('demo_mode.token'));

        return DB::connection('demo');
    }

    protected function seedSource(): void
    {
        $db = $this->source();
        $teams = array_map(fn ($n) => $db->table('teams')->insertGetId(['name' => "Team {$n}"]), range(1, 3));
        $people = [];
        foreach (range(1, 7) as $n) {
            $people[] = $db->table('people')->insertGetId([
                'name' => "person {$n}", 'team_id' => $teams[$n % 3], 'manager_id' => $people[$n - 2] ?? null,
                'meta' => json_encode(['n' => $n]),
            ]);
        }
        // Foreign key cycle: a team's captain belongs to the team.
        $db->table('teams')->where('id', $teams[0])->update(['captain_id' => $people[2]]);
        foreach ([[0, 0], [0, 1], [1, 2], [1, 3], [2, 4]] as [$team, $person]) {
            $db->table('memberships')->insert(['team_id' => $teams[$team], 'person_id' => $people[$person], 'role' => 'member']);
        }
        foreach (range(1, 5) as $n) {
            $db->table('logs')->insert(['message' => "log {$n}", 'level' => $n % 2]);
        }
        foreach (range(1, 3) as $n) {
            $db->table('tickets')->insert(['subject' => "ticket {$n}"] + ($db->getDriverName() === 'pgsql' ? [] : ['number' => 1000 + $n]));
        }
    }

    protected function rows(Connection $connection, string $table): array
    {
        $columns = array_column(array_filter($connection->getSchemaBuilder()->getColumns($table),
            fn (array $column): bool => $column['type_name'] !== 'json'), 'name');
        $query = $connection->table($table);
        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        return $query->get()->map(fn ($row) => array_map(fn ($value) => is_string($value) && str_starts_with($value, '{')
            ? json_decode($value, true) : $value, (array) $row))->all();
    }

    protected function provisionUntilFailure(): ProvisioningFailed
    {
        $manager = app(DemoManager::class);
        $manager->begin();
        try {
            for ($step = 0; $step < 200; $step++) {
                if ($manager->advance()['status'] === 'complete') {
                    $this->fail('Provisioning was expected to fail.');
                }
            }
        } catch (ProvisioningFailed $exception) {
            return $exception;
        }
        $this->fail('Provisioning did not finish.');
    }

    public function test_copies_every_row_across_chunks_keys_and_cycles(): void
    {
        $this->seedSource();

        app(DemoManager::class)->start();

        $demo = $this->demo();
        foreach (['teams', 'people', 'memberships', 'logs', 'tickets', 'users'] as $table) {
            $this->assertEquals($this->rows($this->source(), $table), $this->rows($demo, $table), "Table {$table} differs.");
        }
        $this->assertTrue($demo->getSchemaBuilder()->hasTable('entries'), 'The named-connection migration ran in the sandbox.');
        $this->assertSame([], $this->driver()->verify(app(DemoManager::class)->ref(session('demo_mode.token')), $demo, new TablePlan));
    }

    public function test_sequences_continue_after_the_copied_keys(): void
    {
        $this->seedSource();
        $maxPerson = $this->source()->table('people')->max('id');
        app(DemoManager::class)->start();

        $runtime = app(SandboxRuntime::class);
        $runtime->enter(session('demo_mode.token'));
        try {
            $this->assertGreaterThan($maxPerson, DB::table('people')->insertGetId(['name' => 'new']));
            $this->assertGreaterThan(3, DB::table('teams')->insertGetId(['name' => 'new']));
        } finally {
            $runtime->leave();
        }
        $this->assertSame(7, $this->source()->table('people')->count());
    }

    public function test_writes_during_the_demo_never_reach_the_source(): void
    {
        $this->seedSource();
        app(DemoManager::class)->start();

        $runtime = app(SandboxRuntime::class);
        $runtime->enter(session('demo_mode.token'));
        try {
            DB::table('logs')->insert(['message' => 'default connection', 'level' => 1]);
            // The source connection name resolves to the sandbox.
            DB::connection('source')->table('logs')->insert(['message' => 'named connection', 'level' => 1]);
            DB::table('memberships')->delete();
        } finally {
            $runtime->leave();
        }

        $this->assertSame(5, $this->source()->table('logs')->count());
        $this->assertSame(5, $this->source()->table('memberships')->count());
        $this->assertSame(7, $this->demo()->table('logs')->count());
        $this->assertSame(0, $this->demo()->table('memberships')->count());
    }

    public function test_missing_relationships_fail_and_keep_the_saved_demo(): void
    {
        $this->seedSource();
        $manager = app(DemoManager::class);
        $manager->start();
        $saved = session('demo_mode.token');
        $savedRef = $manager->ref($saved);
        $manager->stop();
        $this->insertOrphan($this->source());

        $exception = $this->provisionUntilFailure();

        $this->assertStringContainsString('missing relationships', $exception->getMessage());
        $this->assertSame($saved, $manager->currentToken());
        $this->assertTrue($manager->canRestore());
        $this->assertEqualsCanonicalizing([$savedRef->container], $this->driver()->list());
    }

    public function test_cancel_discards_the_new_container(): void
    {
        $this->seedSource();
        $manager = app(DemoManager::class);
        $manager->start();
        $saved = $manager->ref(session('demo_mode.token'));
        $manager->stop();

        $manager->begin();
        $pending = $manager->ref(session('demo_mode_provisioning'));
        $manager->advance();
        $this->assertTrue($this->driver()->exists($pending));
        $manager->cancel();

        $this->assertFalse($this->driver()->exists($pending));
        $this->assertTrue($this->driver()->exists($saved));
        $this->assertTrue($manager->canRestore());
    }

    public function test_reset_replaces_the_sandbox_and_drop_leaves_nothing(): void
    {
        $this->seedSource();
        $manager = app(DemoManager::class);
        $manager->start();
        $first = $manager->ref(session('demo_mode.token'));
        $manager->stop();
        $manager->start();
        $second = $manager->ref(session('demo_mode.token'));

        $this->assertFalse($this->driver()->exists($first));
        $this->assertSame([$second->container], $this->driver()->list());
        $manager->stop();
        $this->driver()->drop($second);
        $this->assertSame([], $this->driver()->list());
        $this->assertFalse($manager->canRestore());
    }

    public function test_drifted_columns_fail_with_migrations_and_copy_with_clone(): void
    {
        $this->seedSource();
        $this->source()->statement('alter table people add column nickname varchar(50)');
        $this->source()->table('people')->update(['nickname' => 'nick']);

        $this->assertStringContainsString('people.nickname', $this->provisionUntilFailure()->getMessage());

        config(['demo-mode.sandbox.schema' => 'clone']);
        app(DemoManager::class)->start();
        $this->assertSame(7, $this->demo()->table('people')->where('nickname', 'nick')->count());
        $this->assertEquals($this->rows($this->source(), 'memberships'), $this->rows($this->demo(), 'memberships'));
    }

    public function test_column_mismatch_can_be_reported_as_a_warning(): void
    {
        $this->seedSource();
        $this->source()->statement('alter table people add column nickname varchar(50)');
        config(['demo-mode.sandbox.on_column_mismatch' => 'warn']);
        $manager = app(DemoManager::class);
        $manager->begin();
        do {
            $progress = $manager->advance();
        } while ($progress['status'] === 'running');

        $this->assertStringContainsString('people.nickname', implode(' ', $progress['warnings']));
        $this->assertSame(7, $this->demo()->table('people')->count());
    }

    public function test_doctor_provisions_a_throwaway_sandbox(): void
    {
        $this->seedSource();

        $this->artisan('demo-mode:doctor', ['--provision' => true])
            ->expectsOutputToContain('PASS Sandbox built')
            ->assertSuccessful();

        $this->assertSame([], $this->driver()->list());
    }
}
