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
            ->fillForm(['models' => []])
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
            ->assertSchemaStateSet(['models' => [Widget::class]])
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
}
