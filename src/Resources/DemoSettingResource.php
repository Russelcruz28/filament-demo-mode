<?php

namespace DemoMode\Resources;

use DemoMode\DemoManager;
use DemoMode\Models\DemoSetting;
use DemoMode\Resources\Pages\EditDemoSetting;
use DemoMode\Resources\Pages\ListDemoSettings;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
            Section::make('Starting data')->icon('heroicon-o-circle-stack')
                ->description('Choose the records copied into each new demo, grouped as they appear in the panel navigation. Records they depend on are copied automatically.')
                ->schema(static::modelGroupFields())
                ->columnSpanFull(),
            Section::make('Demo users')->icon('heroicon-o-user-group')
                ->description('These users always sign in to the saved demo. They never see production data and cannot switch roles, reset or exit the demo. Users who can manage Demo Mode are never affected. Users created after the demo was prepared need a demo reset.')
                ->schema([
                    Select::make('users')->label('Demo users')->hiddenLabel()
                        ->placeholder('Search users to tag')
                        ->multiple()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => static::searchUsers($search))
                        ->getOptionLabelsUsing(fn (array $values): array => static::userLabels($values))
                        // Stored as strings so integer, UUID and ULID keys compare the same way.
                        ->dehydrateStateUsing(fn (?array $state): array => array_values(array_map('strval', $state ?? []))),
                ])
                ->visible(fn (): bool => (bool) config('demo-mode.demo_users.enabled', true))
                ->columnSpanFull(),
        ]);
    }

    /** @return array<Component> One collapsible checklist per model group, stored under `model_groups.<key>`. */
    protected static function modelGroupFields(): array
    {
        $fields = [];
        foreach (static::modelGroups() as $key => $group) {
            $count = count($group['options']);
            $fields[] = Section::make($group['label'])
                ->description($count === 1 ? '1 model' : "{$count} models")
                ->compact()->collapsible()
                ->schema([
                    CheckboxList::make("model_groups.{$key}")->label($group['label'])->hiddenLabel()
                        ->options($group['options'])
                        ->descriptions($group['tables'])
                        ->bulkToggleable()
                        ->searchable($count > 9)
                        ->columns(['sm' => 2, 'xl' => 3])->gridDirection('row'),
                ]);
        }

        return $fields !== [] ? $fields : [Text::make('No models are available. Check the models, model_path and excluded_tables settings in config/demo-mode.php.')];
    }

    /**
     * Selectable models grouped for the form, keyed by a state-safe group key.
     *
     * @return array<string, array{label: string, options: array<class-string<Model>, string>, tables: array<class-string<Model>, string>}>
     */
    public static function modelGroups(): array
    {
        $groups = [];
        foreach (app(DemoManager::class)->modelGroups() as $label => $options) {
            $key = Str::slug($label, '_') ?: 'group';
            $key = isset($groups[$key]) ? $key.'_'.count($groups) : $key;
            $tables = [];
            foreach (array_keys($options) as $class) {
                $tables[$class] = (new $class)->getTable();
            }
            $groups[$key] = ['label' => $label, 'options' => $options, 'tables' => $tables];
        }

        return $groups;
    }

    /**
     * Split the saved model list into the form's groups. Models that are no longer offered (e.g. their
     * table was excluded) are dropped so the form still saves.
     *
     * @return array<string, list<class-string<Model>>>
     */
    public static function groupModelSelection(?array $models): array
    {
        return array_map(fn (array $group): array => array_values(array_intersect($models ?? [], array_keys($group['options']))),
            static::modelGroups());
    }

    /** @return list<class-string<Model>> */
    public static function flattenModelSelection(?array $groups): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn ($selected): array => array_values((array) $selected),
            array_values($groups ?? [])))));
    }

    /** @return class-string<Model> */
    protected static function userModel(): string
    {
        $provider = Filament::auth()->getProvider();

        return method_exists($provider, 'getModel') ? $provider->getModel() : config('auth.providers.users.model');
    }

    /** @return array<string, string> */
    protected static function searchUsers(string $search): array
    {
        $model = static::userModel();
        $user = new $model;
        $schema = $user->getConnection()->getSchemaBuilder();
        $columns = array_filter((array) config('demo-mode.demo_users.search_columns', ['name', 'email']),
            fn (string $column): bool => $schema->hasColumn($user->getTable(), $column));

        return $model::query()
            ->where(function ($query) use ($columns, $search, $user): void {
                foreach ($columns as $column) {
                    $query->orWhereLike($column, '%'.$search.'%');
                }
                if (ctype_digit($search)) {
                    $query->orWhere($user->getKeyName(), $search);
                }
            })
            ->limit(50)->get()
            ->mapWithKeys(fn (Model $user): array => [(string) $user->getKey() => static::userLabel($user)])->all();
    }

    /** @return array<string, string> */
    protected static function userLabels(array $values): array
    {
        $model = static::userModel();
        $users = $model::query()->whereKey($values)->get()->keyBy(fn (Model $user): string => (string) $user->getKey());

        // Deleted users stay visible so they can be removed from the list.
        return collect($values)->mapWithKeys(fn ($id): array => [(string) $id => isset($users[(string) $id])
            ? static::userLabel($users[(string) $id]) : 'Deleted user #'.$id])->all();
    }

    protected static function userLabel(Model $user): string
    {
        $email = $user->getAttribute('email');

        return Filament::getUserName($user).($email ? ' ('.$email.')' : '');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Configuration')->formatStateUsing(fn () => 'Demo Mode'),
            TextColumn::make('model_count')->label('Selected models')->state(fn ($record) => count($record->models ?? [])),
            TextColumn::make('user_count')->label('Demo users')->state(fn ($record) => count($record->users ?? [])),
            TextColumn::make('demo_status')->label('Demo')->badge()
                ->state(function (): string {
                    $demo = app(DemoManager::class)->liveDemo();

                    return match (true) {
                        $demo === null => 'Not started',
                        filled($demo['started_at']) => 'Live since '.Carbon::parse($demo['started_at'])->diffForHumans(),
                        default => 'Live',
                    };
                })
                ->color(fn (string $state): string => $state === 'Not started' ? 'gray' : 'success'),
            TextColumn::make('updated_at')->label('Updated')->dateTime(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDemoSettings::route('/'), 'edit' => EditDemoSetting::route('/{record}/edit')];
    }
}
