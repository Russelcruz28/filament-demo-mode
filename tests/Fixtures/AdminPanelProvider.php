<?php

namespace DemoMode\Tests\Fixtures;

use DemoMode\DemoModePlugin;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->path('admin')->default()
            ->plugins([DemoModePlugin::make()]);
    }
}
