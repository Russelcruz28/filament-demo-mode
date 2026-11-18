<?php

namespace DemoMode\Tests;

use DemoMode\DemoManager;
use DemoMode\DemoModeOptions;
use DemoMode\Models\DemoSetting;
use DemoMode\Tests\Fixtures\User;
use DemoMode\Tests\Fixtures\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class DemoUsersTest extends TestCase
{
    private User $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.providers.users.model' => User::class]);
        app(DemoModeOptions::class)->authorize = fn ($user) => $user->name === 'Presenter';
        app(DemoModeOptions::class)->prepare = fn () => null;
        app(DemoModeOptions::class)->exit = fn () => '/login';
        $this->presenter = User::create(['name' => 'Presenter']);
        Widget::create(['name' => 'Production']);
        Route::middleware('web')->get('/probe', fn () => [
            'connection' => DB::getDefaultConnection(), 'user' => auth()->user()?->name, 'widgets' => Widget::count(),
        ]);
        Route::middleware('web')->post('/probe', fn () => Widget::create(['name' => 'Demo only']));
    }

    /** A presenter prepares the saved demo, then exits. */
    private function prepareDemo(): string
    {
        auth()->login($this->presenter);
        app(DemoManager::class)->start();
        $token = session('demo_mode.token');
        app(DemoManager::class)->stop();
        auth()->logout();

        return $token;
    }

    private function tag(User ...$users): void
    {
        DemoSetting::query()->updateOrCreate(['id' => 1], ['users' => array_map(fn (User $user) => (string) $user->id, $users)]);
    }

    public function test_tagged_user_always_works_in_the_saved_demo(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $token = $this->prepareDemo();
        $this->tag($visitor);
        auth()->login($visitor);

        $this->get('/probe')->assertOk()->assertJson(['connection' => 'demo', 'user' => 'Visitor', 'widgets' => 1]);
        $this->post('/probe')->assertCreated();
        $this->get('/probe')->assertJson(['connection' => 'demo', 'widgets' => 2]);

        $this->assertSame(1, Widget::count());
        $this->assertTrue(session('demo_mode.visitor'));
        $this->assertSame($token, session('demo_mode.token'));
        $this->get(route('demo-mode.roles'))->assertForbidden();
        $this->post(route('demo-mode.reset'))->assertForbidden();
    }

    public function test_exit_signs_the_demo_user_out_and_keeps_the_saved_demo(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $token = $this->prepareDemo();
        $this->tag($visitor);
        auth()->login($visitor);
        $this->get('/probe')->assertJson(['connection' => 'demo']);

        $this->post(route('demo-mode.exit'))->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame($token, app(DemoManager::class)->currentToken());
        auth()->login($this->presenter);
        $this->assertTrue(app(DemoManager::class)->canRestore());
    }

    public function test_demo_user_follows_the_demo_after_a_reset(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $old = $this->prepareDemo();
        $new = $this->prepareDemo();
        $this->tag($visitor);
        auth()->login($visitor);
        session(['demo_mode' => ['token' => $old, 'owner' => null, 'visitor' => true, 'user' => (string) $visitor->id]]);

        $this->get('/probe')->assertJson(['connection' => 'demo', 'user' => 'Visitor']);

        $this->assertSame($new, session('demo_mode.token'));
    }

    public function test_demo_user_is_signed_out_when_no_demo_is_ready(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $this->tag($visitor);
        auth()->login($visitor);

        $this->get('/probe')->assertRedirect(route('demo-mode.unavailable'));

        $this->assertGuest();
        $this->get(route('demo-mode.unavailable'))->assertStatus(503)->assertSee('There is no demo ready for your account yet.');
    }

    public function test_demo_user_created_after_the_demo_is_signed_out(): void
    {
        $this->prepareDemo();
        $visitor = User::create(['name' => 'Late visitor']);
        $this->tag($visitor);
        auth()->login($visitor);

        $this->get('/probe')->assertRedirect(route('demo-mode.unavailable'));

        $this->assertGuest();
        $this->get(route('demo-mode.unavailable'))->assertSee('added after this demo was prepared');
    }

    public function test_livewire_requests_receive_a_redirect_when_signed_out(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $this->tag($visitor);
        auth()->login($visitor);

        $this->json('GET', '/probe', ['components' => [['snapshot' => '{}']]], ['X-Livewire' => 'true'])
            ->assertOk()->assertJsonPath('components.0.effects.redirect', route('demo-mode.unavailable'));
    }

    public function test_untagged_user_returns_to_production(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $this->prepareDemo();
        $this->tag($visitor);
        auth()->login($visitor);
        $this->get('/probe')->assertJson(['connection' => 'demo']);

        $this->tag();

        $this->get('/probe')->assertJson(['connection' => 'testing', 'user' => 'Visitor']);
        $this->assertFalse(session()->has('demo_mode'));
    }

    public function test_users_who_can_manage_demo_mode_are_never_forced_in(): void
    {
        $this->prepareDemo();
        $this->tag($this->presenter);
        auth()->login($this->presenter);

        $this->get('/probe')->assertJson(['connection' => 'testing', 'user' => 'Presenter']);
    }

    public function test_demo_users_can_be_tagged_by_a_callback(): void
    {
        User::create(['name' => 'Prospect']);
        $this->prepareDemo();
        app(DemoModeOptions::class)->demoUser = fn ($user) => $user->name === 'Prospect';
        auth()->login(User::where('name', 'Prospect')->firstOrFail());

        $this->get('/probe')->assertJson(['connection' => 'demo', 'user' => 'Prospect']);
    }

    public function test_demo_users_can_be_disabled(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $this->prepareDemo();
        $this->tag($visitor);
        config(['demo-mode.demo_users.enabled' => false]);
        auth()->login($visitor);

        $this->get('/probe')->assertJson(['connection' => 'testing']);
    }
}
