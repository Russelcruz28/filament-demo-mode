<?php

namespace DemoMode\Tests;

use DemoMode\DemoManager;
use DemoMode\DemoModeOptions;
use DemoMode\Metadata\DatabaseMetadataStore;
use DemoMode\Models\DemoSetting;
use DemoMode\ProvisioningFailed;
use DemoMode\Tests\Fixtures\User;
use DemoMode\Tests\Fixtures\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Schema sources, shared metadata and locks, doctor and install on the SQLite driver.
 */
class SandboxOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'Presenter']));
        app(DemoModeOptions::class)->authorize = fn () => true;
        app(DemoModeOptions::class)->prepare = fn () => null;
        config(['demo-mode.provisioning.chunk_size' => 2, 'demo-mode.provisioning.step_seconds' => 0]);
        foreach (range(1, 3) as $number) {
            Widget::create(['name' => 'Widget '.$number]);
        }
    }

    private function demoWidgets(): array
    {
        app(DemoManager::class)->configure(session('demo_mode.token'));

        return DB::connection('demo')->table('widgets')->orderBy('id')->pluck('name')->all();
    }

    public function test_clone_copies_a_sqlite_source_and_empties_unselected_tables(): void
    {
        DemoSetting::query()->create(['id' => 1, 'models' => [Widget::class]]);
        config(['demo-mode.sandbox.schema' => 'clone']);

        app(DemoManager::class)->start(enter: true);

        $this->assertSame(['Widget 1', 'Widget 2', 'Widget 3'], $this->demoWidgets());
        $this->assertSame(0, DB::connection('demo')->table('demo_settings')->count());
        $this->assertSame(1, DemoSetting::query()->count());
    }

    public function test_dump_loads_a_schema_file_then_copies_rows(): void
    {
        $path = config('demo-mode.root').'/schema.sql';
        File::ensureDirectoryExists(dirname($path));
        $statements = array_column(DB::select("select sql from sqlite_master where sql is not null and name not like 'sqlite_%'"), 'sql');
        $migrations = DB::table('migrations')->get()->map(fn ($row) => sprintf("insert into migrations (migration, batch) values ('%s', %d)", $row->migration, $row->batch));
        File::put($path, implode(";\n", [...$statements, ...$migrations]).";\n");
        config(['demo-mode.sandbox.schema' => 'dump', 'demo-mode.sandbox.schema_path' => $path]);

        app(DemoManager::class)->start(enter: true);

        $this->assertSame(['Widget 1', 'Widget 2', 'Widget 3'], $this->demoWidgets());
    }

    public function test_dump_without_a_file_is_refused_before_provisioning(): void
    {
        config(['demo-mode.sandbox.schema' => 'dump', 'demo-mode.sandbox.schema_path' => '/missing/schema.sql']);

        $this->expectException(ProvisioningFailed::class);
        $this->expectExceptionMessage('Schema dump not found');
        app(DemoManager::class)->begin();
    }

    public function test_unsupported_driver_is_refused_before_provisioning(): void
    {
        config(['demo-mode.sandbox.driver' => 'pgsql-schema']);

        $this->expectException(ProvisioningFailed::class);
        $this->expectExceptionMessage('needs a PostgreSQL source');
        app(DemoManager::class)->begin();
    }

    public function test_auto_driver_keeps_the_source_engine(): void
    {
        config(['demo-mode.sandbox.driver' => 'auto']);

        app(DemoManager::class)->start(enter: true);

        $this->assertSame('sqlite', app(DemoManager::class)->ref(session('demo_mode.token'))->driver);
    }

    public function test_database_metadata_and_cache_lock_support_restore(): void
    {
        config(['demo-mode.metadata' => 'database', 'demo-mode.lock' => 'cache']);
        $manager = app(DemoManager::class);
        $manager->start(enter: true);
        $token = session('demo_mode.token');
        $manager->stop();
        session()->forget('demo_mode_saved');

        $this->assertFileDoesNotExist(config('demo-mode.root').'/current.json');
        $this->assertSame('current', DB::table(DatabaseMetadataStore::TABLE)->where('token', $token)->value('status'));
        $this->assertSame(1, DB::table(DatabaseMetadataStore::TABLE)->count());
        $this->assertTrue($manager->canRestore());
        $manager->restore();
        $this->assertSame($token, session('demo_mode.token'));
    }

    public function test_database_lock_falls_back_to_a_file_lock_on_sqlite(): void
    {
        config(['demo-mode.lock' => 'database']);

        app(DemoManager::class)->start(enter: true);

        $this->assertFileExists(config('demo-mode.root').'/.sandbox.lock');
    }

    public function test_sandboxes_saved_before_drivers_existed_are_restorable(): void
    {
        $manager = app(DemoManager::class);
        $manager->start(enter: true);
        $token = session('demo_mode.token');
        $manager->stop();
        // The 0.x metadata format had no sandbox reference.
        File::put(config('demo-mode.root').'/current.json', json_encode(['token' => $token, 'owner' => auth()->id(), 'context' => []]));
        config(['demo-mode.sandbox.driver' => 'auto']);

        $this->assertTrue($manager->canRestore());
        $this->assertSame('sqlite', $manager->ref($token)->driver);
    }

    public function test_doctor_provision_builds_and_drops_a_throwaway_sandbox(): void
    {
        $this->artisan('demo-mode:doctor', ['--provision' => true])
            ->expectsOutputToContain('PASS Sandbox built')
            ->assertSuccessful();

        $this->assertSame([], File::directories(config('demo-mode.root')));
    }

    public function test_doctor_fails_for_an_unsupported_driver(): void
    {
        config(['demo-mode.sandbox.driver' => 'pgsql-schema']);

        $this->artisan('demo-mode:doctor')->expectsOutputToContain('FAIL Sandbox driver pgsql-schema')->assertFailed();
    }

    public function test_doctor_fails_for_migrations_on_a_blocked_connection(): void
    {
        $this->app['migrator']->path(__DIR__.'/Fixtures/driver-migrations');
        config(['database.connections.source' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $this->artisan('demo-mode:doctor')->expectsOutputToContain('connection, which is blocked')->assertFailed();

        config(['demo-mode.connections.sandbox' => ['source']]);
        $this->artisan('demo-mode:doctor')->expectsOutputToContain('overrides its connection')->assertSuccessful();
    }

    public function test_install_publishes_the_auto_driver_for_new_installs(): void
    {
        $path = config_path('demo-mode.php');
        File::delete($path);
        try {
            $this->artisan('demo-mode:install')->assertSuccessful();
            $this->assertStringContainsString("env('DEMO_MODE_DRIVER', 'auto')", File::get($path));
        } finally {
            File::delete($path);
        }
    }
}
