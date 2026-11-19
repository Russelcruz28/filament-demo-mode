<?php

namespace DemoMode\Tests;

use DemoMode\DemoManager;
use DemoMode\DemoModeOptions;
use DemoMode\Http\DemoScope;
use DemoMode\Models\DemoSetting;
use DemoMode\Tests\Fixtures\User;
use DemoMode\Tests\Fixtures\Widget;
use Illuminate\Http\Request;
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
        Route::middleware(['web', 'demo-mode'])->get('/probe', fn () => [
            'connection' => DB::getDefaultConnection(), 'user' => auth()->user()?->name, 'widgets' => Widget::count(),
        ]);
        Route::middleware(['web', 'demo-mode'])->post('/probe', fn () => Widget::create(['name' => 'Demo only']));
        // A page outside the demo panels, e.g. another panel or the public site.
        Route::middleware('web')->get('/elsewhere', fn () => ['connection' => DB::getDefaultConnection()]);
    }

    /** An administrator starts the demo; it goes live without them entering it. */
    private function prepareDemo(): string
    {
        auth()->login($this->presenter);
        app(DemoManager::class)->start();
        $this->assertFalse(session()->has('demo_mode'));
        auth()->logout();

        return app(DemoManager::class)->currentToken();
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

    public function test_stopping_the_demo_signs_demo_users_out(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $token = $this->prepareDemo();
        $this->tag($visitor);
        auth()->login($visitor);
        $this->get('/probe')->assertJson(['connection' => 'demo']);

        auth()->login($this->presenter);
        app(DemoManager::class)->end();
        auth()->login($visitor);

        $this->assertNull(app(DemoManager::class)->liveDemo());
        $this->assertDirectoryDoesNotExist(app(DemoManager::class)->directory($token));
        $this->get('/probe')->assertRedirect(route('demo-mode.unavailable'));
        $this->assertGuest();
        $this->assertSame(1, Widget::count());
    }

    public function test_starter_can_still_choose_to_enter_the_demo(): void
    {
        auth()->login($this->presenter);
        config(['demo-mode.enter_on_start' => true]);

        app(DemoManager::class)->start();

        $this->assertSame(app(DemoManager::class)->currentToken(), session('demo_mode.token'));
        $this->get('/probe')->assertJson(['connection' => 'demo', 'user' => 'Presenter']);
    }

    public function test_reset_from_inside_the_demo_moves_the_presenter_to_the_new_demo(): void
    {
        auth()->login($this->presenter);
        $manager = app(DemoManager::class);
        $manager->start(enter: true);
        $old = session('demo_mode.token');

        $manager->start();

        $this->assertNotSame($old, session('demo_mode.token'));
        $this->assertSame($manager->currentToken(), session('demo_mode.token'));
    }

    public function test_demo_users_are_told_when_they_enter_and_leave_the_demo(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $this->prepareDemo();
        $this->tag($visitor);
        auth()->login($visitor);

        $this->get('/probe');
        $this->assertSame('entered', session('demo_mode_notice'));
        session()->forget('demo_mode_notice');
        $this->get('/probe');
        $this->assertNull(session('demo_mode_notice'), 'Only the first page explains the change.');

        // The administrator resets from their own browser session.
        $visitorState = session()->pull('demo_mode');
        auth()->login($this->presenter);
        app(DemoManager::class)->start();
        auth()->login($visitor);
        session(['demo_mode' => $visitorState]);
        $this->get('/probe');
        $this->assertSame('refreshed', session('demo_mode_notice'));

        $this->tag();
        $this->get('/probe');
        $this->assertSame('removed', session('demo_mode_notice'));
    }

    public function test_presenters_are_told_when_they_enter_leave_or_the_demo_stops(): void
    {
        $this->prepareDemo();
        auth()->login($this->presenter);
        $manager = app(DemoManager::class);

        $manager->restore();
        $this->assertSame('entered', session('demo_mode_notice'));
        $this->post(route('demo-mode.exit'))->assertRedirect();
        $this->assertSame('exited', session('demo_mode_notice'));

        $manager->restore();
        $manager->end();
        $this->get('/probe')->assertRedirect();
        $this->assertSame('ended', session('demo_mode_notice'));
    }

    public function test_presenters_use_live_data_outside_the_demo_panels(): void
    {
        auth()->login($this->presenter);
        app(DemoManager::class)->start(enter: true);

        $this->get('/probe')->assertJson(['connection' => 'demo', 'widgets' => 1]);
        $this->get('/elsewhere')->assertJson(['connection' => 'testing']);

        $this->assertTrue(session()->has('demo_mode'));
        $this->get('/probe')->assertJson(['connection' => 'demo']);
    }

    public function test_demo_users_never_reach_live_data_outside_the_demo_panels(): void
    {
        $visitor = User::create(['name' => 'Visitor']);
        $this->prepareDemo();
        $this->tag($visitor);
        auth()->login($visitor);

        // Without a registered demo panel there is nowhere to send them.
        $this->get('/elsewhere')->assertForbidden();
        $this->get('/probe')->assertJson(['connection' => 'demo', 'user' => 'Visitor']);
    }

    public function test_livewire_updates_follow_the_page_that_rendered_the_component(): void
    {
        $scope = app(DemoScope::class);
        $update = function (string $path) {
            $request = Request::create('/livewire/update', 'POST', ['components' => [
                ['snapshot' => json_encode(['memo' => ['path' => $path, 'method' => 'GET']])],
            ]]);
            $route = app('router')->getRoutes()->getByName('default-livewire.update');
            $request->setRouteResolver(fn () => $route);

            return $request;
        };

        $this->assertTrue($scope->covers($update('probe')));
        $this->assertFalse($scope->covers($update('elsewhere')));
        $this->assertFalse($scope->covers($update('missing')));
    }
}
