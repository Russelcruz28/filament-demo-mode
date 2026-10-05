<?php

namespace DemoMode\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use DemoMode\DemoModeOptions;
use DemoMode\Models\DemoSetting;
use DemoMode\Resources\DemoSettingResource;
use DemoMode\Resources\Pages\EditDemoSetting;
use DemoMode\Resources\Pages\ListDemoSettings;
use DemoMode\Tests\Fixtures\User;
use DemoMode\Tests\Fixtures\Widget;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
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
}
