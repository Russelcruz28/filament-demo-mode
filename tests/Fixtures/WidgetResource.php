<?php

namespace DemoMode\Tests\Fixtures;

use Filament\Resources\Resource;

class WidgetResource extends Resource
{
    protected static ?string $model = Widget::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    public static function getPages(): array
    {
        return [];
    }
}
