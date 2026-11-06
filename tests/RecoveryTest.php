<?php

namespace DemoMode\Tests;

use DemoMode\DemoManager;
use DemoMode\DemoModeOptions;
use DemoMode\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class RecoveryTest extends TestCase
{
    private function startDemo(): string
    {
        config(['auth.providers.users.model' => User::class]);
        auth()->login(User::create(['name' => 'Presenter']));
        app(DemoModeOptions::class)->authorize = fn () => true;
        app(DemoModeOptions::class)->prepare = fn () => null;
        app(DemoManager::class)->start();
        Route::middleware('web')->get('/missing-demo-table', fn () => DB::table('new_table')->get());
        Route::middleware('web')->get('/missing-demo-column', fn () => DB::table('widgets')->select('widgets.new_column')->get());

        return session('demo_mode.token');
    }

    public function test_missing_table_redirects_to_actionable_recovery_without_deleting_demo(): void
    {
        $token = $this->startDemo();
        $this->get('/missing-demo-table')->assertRedirect(route('demo-mode.recovery'));
        $this->get(route('demo-mode.recovery'))->assertOk()->assertSee('Reset demo')->assertSee('Exit demo');
        $this->assertFileExists(app(DemoManager::class)->directory($token).'/database.sqlite');
        $this->assertFalse(app(DemoManager::class)->active());
        $this->assertSame('testing', DB::getDefaultConnection());
        $this->from(route('demo-mode.recovery'))->post(route('demo-mode.reset'))->assertRedirect(route('demo-mode.recovery'));
        $this->get(route('demo-mode.recovery'))->assertOk()->assertSee('Preparing demo');
        do {
            $response = $this->postJson(route('demo-mode.provisioning.step'))->assertOk();
        } while ($response->json('status') !== 'complete');
        $this->assertNotSame($token, session('demo_mode.token'));
    }

    public function test_missing_column_returns_recovery_details_for_json_requests(): void
    {
        $this->startDemo();
        $this->getJson('/missing-demo-column')->assertStatus(409)->assertJsonPath('recovery_url', route('demo-mode.recovery'));
    }

    public function test_livewire_receives_redirect_effect(): void
    {
        $this->startDemo();
        $this->json('GET', '/missing-demo-table', ['components' => [['snapshot' => '{}']]], ['X-Livewire' => 'true'])
            ->assertOk()->assertJsonPath('components.0.effects.redirect', route('demo-mode.recovery'));
    }

    public function test_recovery_requires_an_active_owned_demo(): void
    {
        $this->get(route('demo-mode.recovery'))->assertForbidden();
    }
}
