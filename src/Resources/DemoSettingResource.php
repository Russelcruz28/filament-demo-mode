<?php

namespace DemoMode\Resources;

use DemoMode\DemoManager;
use DemoMode\Models\DemoSetting;
use DemoMode\Resources\Pages\EditDemoSetting;
use DemoMode\Resources\Pages\ListDemoSettings;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DemoSettingResource extends Resource
{
    protected static ?string $model = DemoSetting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Demo Mode';

    protected static ?string $modelLabel = 'Demo configuration';

    protected static ?string $pluralModelLabel = 'Demo Mode';

    protected static bool $isScopedToTenant = false;

    public static function canViewAny(): bool
    {
        return app(DemoManager::class)->canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            CheckboxList::make('models')->label('Copy starting data from')
                ->options(fn () => app(DemoManager::class)->modelOptions())
                // Drop saved models that are no longer offered (e.g. their table was excluded) so the form still saves.
                ->afterStateHydrated(fn (CheckboxList $component, ?array $state) => $component->state(
                    array_values(array_intersect($state ?? [], array_keys(app(DemoManager::class)->modelOptions())))
                ))
                ->searchable()->bulkToggleable()->columns(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Configuration')->formatStateUsing(fn () => 'Demo Mode'),
            TextColumn::make('model_count')->label('Selected models')->state(fn ($record) => count($record->models ?? [])),
            TextColumn::make('updated_at')->label('Updated')->dateTime(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDemoSettings::route('/'), 'edit' => EditDemoSetting::route('/{record}/edit')];
    }
}
