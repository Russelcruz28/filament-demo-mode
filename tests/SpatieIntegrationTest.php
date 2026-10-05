<?php

namespace DemoMode\Tests;

use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\DemoManager;
use DemoMode\SandboxRuntime;
use DemoMode\Tests\Fixtures\RoleUser;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

class SpatieIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class, Fixtures\AdminPanelProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $migration = require __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        $migration->up();
        $this->app['migrator']->path(__DIR__.'/Fixtures/permission-migrations');
    }

    public function test_roles_are_discovered_and_changes_stay_in_sandbox(): void
    {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $head = Role::create(['name' => 'office_head', 'guard_name' => 'web']);
        $head->givePermissionTo(Permission::create(['name' => 'approve', 'guard_name' => 'web']));
        Role::create(['name' => 'api_role', 'guard_name' => 'api']);
        $user = RoleUser::create(['name' => 'Presenter']);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        $adapter = app(ApplicationAdapter::class);
        $this->assertTrue($adapter->canManage($user));
        $this->assertEqualsCanonicalizing(['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'], $adapter->requiredTables($user));
        $manager = app(DemoManager::class);
        $manager->start();
        $runtime = app(SandboxRuntime::class);
        $runtime->enter(session('demo_mode.token'));
        try {
            $copied = RoleUser::on('demo')->findOrFail($user->id);
            $this->assertCount(2, $adapter->roles($copied));
            $adapter->switchRole($copied, (string) $head->id);
            $this->assertTrue($copied->fresh()->hasRole('office_head'));
            $this->assertFalse($copied->fresh()->hasRole('super_admin'));
            $this->assertTrue($copied->fresh()->hasPermissionTo('approve'));
            $this->expectException(\LogicException::class);
            DB::connection('testing')->table('users')->count();
        } finally {
            $runtime->leave();
            $this->assertTrue($user->fresh()->hasRole('super_admin'));
            $this->assertFalse($user->fresh()->hasRole('office_head'));
        }
    }
}
