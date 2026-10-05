<?php

namespace DemoMode\Tests;

use DemoMode\Adapters\DefaultApplicationAdapter;
use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\DemoManager;
use DemoMode\DemoModeOptions;
use DemoMode\DemoModePlugin;
use DemoMode\SandboxRuntime;
use DemoMode\Tests\Fixtures\User;
use DemoMode\Tests\Fixtures\Widget;
use Filament\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PluginTest extends TestCase
{
    public function test_default_adapter_denies_unconfigured_users(): void
    {
        $adapter = app(ApplicationAdapter::class);
        $this->assertInstanceOf(DefaultApplicationAdapter::class, $adapter);
        $this->assertFalse($adapter->canManage(null));
        $this->assertFalse($adapter->canManage(new User));
    }

    public function test_plugin_registers_callbacks_without_host_adapter(): void
    {
        DemoModePlugin::make()->authorizeUsing(fn ($user) => $user->name === 'Presenter')
            ->rolesUsing(fn ($user) => [['id' => 'head', 'label' => 'Office Head', 'group' => 'Office', 'selected' => true]])
            ->switchRoleUsing(fn ($user, $id) => '/demo-dashboard')
            ->exitUsing(fn () => '/production')
            ->register(Panel::make()->id('backoffice'));
        $adapter = app(ApplicationAdapter::class);
        $user = new User(['name' => 'Presenter']);
        $this->assertTrue($adapter->canManage($user));
        $this->assertSame('backoffice', config('demo-mode.panel'));
        $this->assertSame('/demo-dashboard', $adapter->switchRole($user, 'head'));
        $this->assertSame('/production', $adapter->destination());
    }

    public function test_secondary_panel_does_not_replace_management_configuration(): void
    {
        DemoModePlugin::make()->authorizeUsing(fn () => true)->register(Panel::make()->id('admin'));
        DemoModePlugin::make()->management(false)->authorizeUsing(fn () => false)->register(Panel::make()->id('app'));
        $this->assertSame('admin', config('demo-mode.panel'));
        $this->assertTrue(app(ApplicationAdapter::class)->canManage(new User));
    }

    public function test_unknown_role_is_rejected_before_callback(): void
    {
        app(DemoModeOptions::class)->roles = fn () => [];
        app(DemoModeOptions::class)->switchRole = fn () => $this->fail('An invalid role reached the callback.');
        $this->expectException(HttpException::class);
        app(ApplicationAdapter::class)->switchRole(new User, 'unknown');
    }

    public function test_sandbox_is_independent_and_restorable_without_expiry(): void
    {
        $user = User::create(['name' => 'Presenter']);
        Widget::create(['name' => 'Production']);
        $this->actingAs($user);
        app(DemoModeOptions::class)->authorize = fn () => true;
        app(DemoModeOptions::class)->prepare = fn () => null;
        $manager = app(DemoManager::class);
        $manager->start();
        $token = session('demo_mode.token');
        $runtime = app(SandboxRuntime::class);
        $runtime->enter($token);
        try {
            $this->assertSame(1, Widget::count());
            Widget::create(['name' => 'Demo only']);
        } finally {
            $runtime->leave();
        }
        $this->assertSame(1, Widget::count());
        $manager->stop();
        session()->flush();
        $this->actingAs($user);
        $this->travel(5)->years();
        $this->assertTrue($manager->canRestore());
        $manager->restore();
        $this->assertSame($token, session('demo_mode.token'));
        $manager->configure($token);
        $this->assertSame(2, DB::connection('demo')->table('widgets')->count());
        $this->assertFileDoesNotExist($manager->directory($token).'/expires_at');
        $manager->stop();
        $manager->start();
        $this->assertCount(1, File::directories(config('demo-mode.root')));
        $this->assertDirectoryDoesNotExist($manager->directory($token));
    }

    public function test_doctor_checks_an_independent_application(): void
    {
        $this->artisan('demo-mode:doctor')->assertSuccessful();
    }

    public function test_doctor_reports_an_invalid_adapter(): void
    {
        config(['demo-mode.adapter' => \stdClass::class]);
        $this->artisan('demo-mode:doctor')->assertFailed();
    }

    public function test_guest_cannot_start_or_restore_a_demo(): void
    {
        $manager = app(DemoManager::class);
        $this->assertFalse($manager->canManage());
        $this->assertFalse($manager->canRestore());
        $this->expectException(HttpException::class);
        $manager->start();
    }
}
