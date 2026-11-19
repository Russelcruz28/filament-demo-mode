<?php

namespace DemoMode\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use DemoMode\DemoManager;
use DemoMode\DemoModeOptions;
use DemoMode\Models\DemoSetting;
use DemoMode\Resources\DemoSettingResource;
use DemoMode\Resources\Pages\EditDemoSetting;
use DemoMode\Resources\Pages\ListDemoSettings;
use DemoMode\SandboxRuntime;
use DemoMode\Tests\Fixtures\User;
use DemoMode\Tests\Fixtures\Widget;
use DemoMode\Tests\Fixtures\WidgetResource;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

class FilamentCompatibilityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            Fixtures\AdminPanelProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create(['name' => 'Presenter']));
        app(DemoModeOptions::class)->authorize = fn () => true;
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_management_resource_lists_the_configuration(): void
    {
        $this->assertContains(DemoSettingResource::class, Filament::getCurrentPanel()->getResources());

        Livewire::test(ListDemoSettings::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(DemoSetting::all())
            ->assertActionExists('startDemo');

        $this->assertSame([Widget::class], DemoSetting::findOrFail(1)->models);
    }

    public function test_management_form_saves_the_model_selection(): void
    {
        $setting = DemoSetting::create(['models' => [Widget::class]]);

        Livewire::test(EditDemoSetting::class, ['record' => $setting->getRouteKey()])
            ->assertSuccessful()
            ->fillForm(['model_groups' => ['general' => []]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([], $setting->fresh()->models);
    }

    public function test_start_demo_shows_the_provisioning_modal(): void
    {
        $this->assertSame('', trim(view('demo-mode::provisioning-modal')->render()));

        Livewire::test(ListDemoSettings::class)
            ->callAction('startDemo')
            ->assertRedirect(ListDemoSettings::getUrl());

        $modal = view('demo-mode::provisioning-modal')->render();
        $this->assertNotNull(app(DemoManager::class)->provisioning());
        $this->assertStringContainsString('Preparing demo', $modal);
        $this->assertStringContainsString(route('demo-mode.provisioning.step'), $modal);
    }

    public function test_saved_models_that_are_no_longer_offered_are_dropped(): void
    {
        $setting = DemoSetting::create(['models' => [Widget::class, 'App\\Models\\RemovedModel']]);

        Livewire::test(EditDemoSetting::class, ['record' => $setting->getRouteKey()])
            ->assertSchemaStateSet(['model_groups' => ['general' => [Widget::class]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([Widget::class], $setting->fresh()->models);
    }

    public function test_start_demo_skips_saved_models_that_are_no_longer_offered(): void
    {
        DemoSetting::create(['models' => [Widget::class, 'App\\Models\\RemovedModel']]);

        app(DemoManager::class)->begin();

        $this->assertNotNull(app(DemoManager::class)->provisioning());
    }

    public function test_management_form_tags_demo_users(): void
    {
        $setting = DemoSetting::create(['models' => [Widget::class]]);
        $visitor = User::create(['name' => 'Visiting Prospect']);
        $search = new \ReflectionMethod(DemoSettingResource::class, 'searchUsers');

        $this->assertSame([(string) $visitor->id => 'Visiting Prospect'], $search->invoke(null, 'prospect'));
        Livewire::test(EditDemoSetting::class, ['record' => $setting->getRouteKey()])
            ->assertFormFieldExists('users')
            ->fillForm(['users' => [(string) $visitor->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([(string) $visitor->id], $setting->fresh()->users);
        $this->assertSame([(string) $visitor->id], app(DemoManager::class)->demoUserIds());
    }

    public function test_demo_user_banner_offers_only_sign_out(): void
    {
        app(DemoModeOptions::class)->prepare = fn () => null;
        app(DemoManager::class)->start(enter: true);
        $token = session('demo_mode.token');
        session(['demo_mode' => ['token' => $token, 'owner' => null, 'visitor' => true, 'user' => (string) auth()->id()]]);
        $runtime = app(SandboxRuntime::class);
        $runtime->enter($token);
        try {
            $banner = view('demo-mode::banner')->render();
        } finally {
            $runtime->leave();
        }

        $this->assertStringContainsString('Sign out', $banner);
        $this->assertStringNotContainsString('Switch role', $banner);
        $this->assertStringNotContainsString('Reset demo', $banner);
    }

    public function test_start_publishes_the_demo_without_entering_it_and_stop_removes_it(): void
    {
        app(DemoModeOptions::class)->prepare = fn () => null;
        Livewire::test(ListDemoSettings::class)
            ->assertActionHasLabel('startDemo', 'Start Demo')
            ->assertActionHidden('stopDemo')
            ->callAction('startDemo');
        do {
            $response = $this->postJson(route('demo-mode.provisioning.step'))->assertOk();
        } while ($response->json('status') === 'running');

        $response->assertJson(['status' => 'complete', 'redirect' => null]);
        $this->assertFalse(session()->has('demo_mode'));
        Notification::assertNotified('Demo is live');
        Livewire::test(ListDemoSettings::class)
            ->assertActionHasLabel('startDemo', 'Reset Demo')
            ->assertActionVisible('restoreDemo')
            ->callAction('stopDemo');

        $this->assertNull(app(DemoManager::class)->liveDemo());
    }

    public function test_top_bar_explains_demo_mode_once(): void
    {
        app(DemoModeOptions::class)->prepare = fn () => null;
        app(DemoManager::class)->start(enter: true);
        $runtime = app(SandboxRuntime::class);

        $runtime->enter(session('demo_mode.token'));
        try {
            $first = view('demo-mode::banner')->render();
            $second = view('demo-mode::banner')->render();
            $badge = view('demo-mode::topbar-badge')->render();
        } finally {
            $runtime->leave();
        }

        $this->assertStringContainsString('Demo mode', $first);
        $this->assertStringContainsString('You entered the demo.', $first);
        $this->assertStringContainsString('[Demo] ', $first);
        $this->assertStringNotContainsString('You entered the demo.', $second);
        $this->assertStringContainsString('Changes here do not affect real data.', $second);
        $this->assertStringContainsString('Demo', $badge);
        $this->assertSame('', trim(view('demo-mode::topbar-badge')->render()));
    }

    public function test_top_bar_confirms_the_return_to_live_data(): void
    {
        app(DemoModeOptions::class)->prepare = fn () => null;
        app(DemoManager::class)->start(enter: true);
        app(DemoManager::class)->stop();

        $live = view('demo-mode::banner')->render();

        $this->assertStringContainsString('You left demo mode. You are now working with live data', $live);
        $this->assertStringNotContainsString('Exit demo', $live);
        $this->assertSame('', trim(view('demo-mode::banner')->render()));
    }

    public function test_notices_are_kept_until_shown_in_the_right_place(): void
    {
        app(DemoManager::class)->notice('entered');

        $this->assertSame('', trim(view('demo-mode::banner')->render()));
        $this->assertSame('entered', session('demo_mode_notice'));
        config(['demo-mode.notices' => false]);
        app(DemoManager::class)->notice('exited');
        $this->assertSame('', trim(view('demo-mode::banner')->render()));
    }

    public function test_demo_users_outside_the_demo_panel_are_sent_back_to_it(): void
    {
        app(DemoModeOptions::class)->prepare = fn () => null;
        app(DemoManager::class)->start();
        $visitor = User::create(['name' => 'Visitor']);
        DemoSetting::query()->updateOrCreate(['id' => 1], ['users' => [(string) $visitor->id]]);
        app(DemoModeOptions::class)->authorize = fn ($user) => $user->name === 'Presenter';
        Route::middleware('web')->get('/customer-portal', fn () => 'live data');
        $this->actingAs($visitor);

        $this->get('/customer-portal')->assertRedirect(Filament::getPanel('admin')->getUrl());
    }

    public function test_models_are_grouped_by_navigation_group_in_panel_order(): void
    {
        config(['demo-mode.models' => [Widget::class, User::class]]);
        Filament::getPanel('admin')->resources([WidgetResource::class]);

        $this->assertSame(['Inventory' => [Widget::class => 'Widget'], 'General' => [User::class => 'User']],
            app(DemoManager::class)->modelGroups());

        app(DemoModeOptions::class)->modelGroup = fn (string $class) => $class === User::class ? 'People' : null;
        Filament::getPanel('admin')->navigationGroups(['People', 'Inventory']);
        $this->assertSame(['People', 'Inventory'], array_keys(app(DemoManager::class)->modelGroups()));

        $setting = DemoSetting::create(['models' => [Widget::class]]);
        Livewire::test(EditDemoSetting::class, ['record' => $setting->getRouteKey()])
            ->assertSchemaStateSet(['model_groups' => ['people' => [], 'inventory' => [Widget::class]]])
            ->assertSee(['Starting data', 'Inventory', 'People', 'widgets'])
            ->fillForm(['model_groups' => ['people' => [User::class], 'inventory' => [Widget::class]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([User::class, Widget::class], $setting->fresh()->models);
    }
}
