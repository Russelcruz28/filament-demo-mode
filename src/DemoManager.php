<?php

namespace DemoMode;

use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\Contracts\LockProvider;
use DemoMode\Contracts\MetadataStore;
use DemoMode\Contracts\RequiredTables;
use DemoMode\Drivers\SqliteDriver;
use DemoMode\Models\DemoSetting;
use DemoMode\Sandbox\Provisioner;
use DemoMode\Sandbox\SandboxDrivers;
use DemoMode\Sandbox\SandboxRef;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DemoManager
{
    private bool $active = false;

    public function active(): bool
    {
        return $this->active;
    }

    public function canManage(): bool
    {
        return config('demo-mode.enabled') && ! $this->active
            && app(ApplicationAdapter::class)->canManage(Auth::user());
    }

    /**
     * Tagged demo users always work in the saved demo and never reach production. Users who can
     * manage Demo Mode are never forced in, so tagging cannot lock out the people who untag.
     */
    public function isDemoUser(?Authenticatable $user): bool
    {
        if ($user === null || ! config('demo-mode.enabled') || ! config('demo-mode.demo_users.enabled', true)) {
            return false;
        }
        $callback = app(DemoModeOptions::class)->demoUser;
        $tagged = in_array((string) $user->getAuthIdentifier(), $this->demoUserIds(), true)
            || ($callback !== null && (bool) $callback($user));

        return $tagged && ! app(ApplicationAdapter::class)->canManage($user);
    }

    /** @return list<string> Identifiers of users tagged in the Demo Mode settings. */
    public function demoUserIds(): array
    {
        try {
            return array_values(array_map('strval', DemoSetting::query()->first()?->users ?? []));
        } catch (QueryException) {
            // The settings table or its users column has not been migrated yet.
            return [];
        }
    }

    /** Whether the current session belongs to a tagged demo user rather than a presenter. */
    public function isVisitor(): bool
    {
        return (bool) session('demo_mode.visitor', false);
    }

    /**
     * Remember a demo-mode change to explain in the top bar on the next page:
     * entered | refreshed (shown inside the demo) and exited | removed | ended (shown on live data).
     */
    public function notice(string $type): void
    {
        session(['demo_mode_notice' => $type]);
    }

    /** Point the current session at the saved demo for a tagged user. */
    public function joinAsVisitor(Authenticatable $user, string $token): void
    {
        $state = session('demo_mode');
        if (($state['token'] ?? null) !== $token || ($state['user'] ?? null) !== (string) $user->getAuthIdentifier()) {
            // Same user, new token: the demo was reset while they were using it.
            $this->notice(isset($state['token']) && ($state['user'] ?? null) === (string) $user->getAuthIdentifier() ? 'refreshed' : 'entered');
            session(['demo_mode' => [
                'token' => $token, 'owner' => null, 'visitor' => true,
                'user' => (string) $user->getAuthIdentifier(), 'original_session' => [],
            ]]);
        }
    }

    public function canRestore(): bool
    {
        // Metadata may live on the source connection, so it is only read outside the sandbox.
        if (! $this->canManage() || session()->has('demo_mode')) {
            return false;
        }
        $saved = $this->savedDemo();
        if (! is_array($saved) || (string) ($saved['owner'] ?? '') !== (string) Auth::id()
            || ! Str::isUuid($saved['token'] ?? '')) {
            return false;
        }

        return $this->exists($saved['token']);
    }

    private function savedDemo(): ?array
    {
        return $this->metadata()->current() ?? session('demo_mode_saved');
    }

    private function metadata(): MetadataStore
    {
        return app(MetadataStore::class);
    }

    private function drivers(): SandboxDrivers
    {
        return app(SandboxDrivers::class);
    }

    private function provisioner(): Provisioner
    {
        return app(Provisioner::class);
    }

    /**
     * The sandbox a token refers to: from the saved demo, the provisioning state, or, for sandboxes
     * created before drivers existed, the SQLite file in the token's directory.
     */
    public function ref(string $token): SandboxRef
    {
        if (! Str::isUuid($token)) {
            throw new \InvalidArgumentException('Invalid demo sandbox token.');
        }
        $saved = $this->savedDemo();
        if (($saved['token'] ?? null) === $token && is_array($saved['ref'] ?? null)) {
            return SandboxRef::fromArray($saved['ref']);
        }
        if (is_array($ref = $this->metadata()->provisioning($token)['ref'] ?? null)) {
            return SandboxRef::fromArray($ref);
        }
        $source = DB::connection();
        $driver = is_file($this->directory($token).'/database.sqlite') ? $this->drivers()->make('sqlite') : $this->drivers()->resolve($source);

        return $driver->ref($token, isset($saved['owner']) ? (string) $saved['owner'] : null, $source);
    }

    /** Whether the token's sandbox container still exists. */
    public function exists(string $token): bool
    {
        try {
            $ref = $this->ref($token);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $this->drivers()->for($ref)->exists($ref);
    }

    /** The demo tagged users work in, if one has been started and still exists. */
    public function liveDemo(): ?array
    {
        $saved = $this->savedDemo();
        if (! is_array($saved) || ! Str::isUuid($saved['token'] ?? '') || ! $this->exists($saved['token'])) {
            return null;
        }

        return ['token' => $saved['token'], 'owner' => $saved['owner'] ?? null, 'started_at' => $saved['started_at'] ?? null];
    }

    /**
     * Stop the live demo: its sandbox is removed, demo users are signed out on their next request
     * and presenters inside it return to production.
     */
    public function end(): void
    {
        abort_unless($this->canManage(), 403);
        $this->withStorageLock(function (): void {
            if (($token = $this->currentToken()) !== null) {
                $ref = $this->ref($token);
                DB::purge('demo');
                $this->drivers()->for($ref)->drop($ref);
                File::deleteDirectory($this->directory($token));
            }
            $this->metadata()->forgetCurrent();
            session()->forget('demo_mode_saved');
        });
    }

    public function currentToken(): ?string
    {
        $token = $this->savedDemo()['token'] ?? null;

        return is_string($token) && Str::isUuid($token) ? $token : null;
    }

    private function persistDemo(array $state, array $context, SandboxRef $ref): void
    {
        $saved = $this->savedDemo();
        $this->metadata()->setCurrent([
            'token' => $state['token'], 'owner' => $state['owner'], 'context' => $context, 'ref' => $ref->toArray(),
            'started_at' => ($saved['token'] ?? null) === $state['token'] ? ($saved['started_at'] ?? null) : now()->toIso8601String(),
        ]);
        File::delete($this->directory($state['token']).'/expires_at');
    }

    public function restore(): void
    {
        abort_unless($this->canRestore(), 403, 'The saved demo is unavailable or you no longer have access.');
        $saved = $this->savedDemo();
        $original = $this->contextSession();
        foreach (array_keys($original) as $key) {
            session()->forget($key);
        }
        app(ApplicationAdapter::class)->clearContext();
        session()->put($saved['context'] ?? []);
        session(['demo_mode' => [
            'token' => $saved['token'], 'owner' => $saved['owner'],
            'original_session' => $original,
        ]]);
        session()->forget('demo_mode_saved');
        session()->regenerate();
        $this->notice('entered');
    }

    public function modelOptions(): array
    {
        $classes = config('demo-mode.models', []);
        if ($classes === []) {
            foreach (File::isDirectory(config('demo-mode.model_path')) ? File::allFiles(config('demo-mode.model_path')) : [] as $file) {
                if ($file->getExtension() === 'php') {
                    $classes[] = config('demo-mode.model_namespace').str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
                }
            }
        }
        $options = [];
        $schema = DB::connection()->getSchemaBuilder();
        // PostgreSQL lists every schema (including sandbox schemas) unless the search path is given.
        $tables = $schema->getTableListing($schema->getCurrentSchemaListing(), schemaQualified: false);
        foreach ($classes as $class) {
            if (is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $model = new $class;
                if ($model->getConnectionName() === null && in_array($model->getTable(), $tables, true)
                    && ! in_array($model->getTable(), config('demo-mode.excluded_tables'), true)) {
                    $options[$class] = Str::headline(class_basename($class));
                }
            }
        }
        asort($options);

        return $options;
    }

    /**
     * The selectable models grouped the way people find them in the panel: by the navigation group of
     * the resource that manages the model, else by its sub-namespace (App\Models\Inventory\Item is
     * "Inventory"), else "General". Groups follow the panel's navigation order, then the alphabet.
     *
     * @return array<string, array<class-string<Model>, string>>
     */
    public function modelGroups(): array
    {
        $callback = app(DemoModeOptions::class)->modelGroup;
        $navigation = $this->resourceNavigationGroups();
        $groups = [];
        foreach ($this->modelOptions() as $class => $label) {
            $group = $this->groupLabel($callback ? $callback($class) : null)
                ?? $this->groupLabel($navigation[$class] ?? null)
                ?? $this->namespaceGroup($class)
                ?? 'General';
            $groups[$group][$class] = $label;
        }
        $order = array_flip($this->panelGroupOrder());
        uksort($groups, fn (string $a, string $b): int => [$a === 'General', $order[$a] ?? PHP_INT_MAX, $a]
            <=> [$b === 'General', $order[$b] ?? PHP_INT_MAX, $b]);

        return $groups;
    }

    /** @return array<class-string<Model>, string|\UnitEnum> Navigation group of each model's resource, demo panel first. */
    private function resourceNavigationGroups(): array
    {
        $groups = [];
        foreach ($this->panelsByPriority() as $panel) {
            foreach ($panel->getResources() as $resource) {
                try {
                    $group = $resource::getNavigationGroup();
                    $model = $resource::getModel();
                } catch (\Throwable) {
                    continue;
                }
                if ($group !== null && $group !== '' && ! isset($groups[$model])) {
                    $groups[$model] = $group;
                }
            }
        }

        return $groups;
    }

    /** @return list<string> Navigation group labels in the order the demo panel declares them. */
    private function panelGroupOrder(): array
    {
        $order = [];
        foreach (($this->panelsByPriority()[0] ?? null)?->getNavigationGroups() ?? [] as $key => $group) {
            $label = $group instanceof NavigationGroup
                ? $this->groupLabel($group->getLabel()) ?? (is_string($key) ? $key : null)
                : $this->groupLabel($group);
            if ($label !== null) {
                $order[] = $label;
            }
        }

        return $order;
    }

    /** @return list<Panel> */
    private function panelsByPriority(): array
    {
        $panels = Filament::getPanels();
        $main = $panels[config('demo-mode.panel')] ?? null;

        return array_values(array_filter([$main, ...array_filter($panels, fn ($panel) => $panel !== $main)]));
    }

    private function groupLabel(mixed $group): ?string
    {
        if ($group instanceof HasLabel) {
            $group = $group->getLabel();
        } elseif ($group instanceof \UnitEnum) {
            $group = Str::headline($group->name);
        }
        if ($group instanceof Htmlable) {
            $group = strip_tags($group->toHtml());
        }

        return is_string($group) && trim($group) !== '' ? trim($group) : null;
    }

    private function namespaceGroup(string $class): ?string
    {
        $namespace = (string) config('demo-mode.model_namespace');
        if ($namespace === '' || ! Str::startsWith($class, $namespace) || ! str_contains(Str::after($class, $namespace), '\\')) {
            return null;
        }

        return Str::headline(Str::before(Str::after($class, $namespace), '\\'));
    }

    public function directory(string $token): string
    {
        if (! Str::isUuid($token)) {
            throw new \InvalidArgumentException('Invalid demo sandbox token.');
        }

        return config('demo-mode.root').'/'.$token;
    }

    public function configure(string $token): void
    {
        $this->drivers()->connect($this->ref($token));
    }

    /**
     * Provision a new sandbox in one call. Web requests use begin() and advance() to avoid timeouts.
     */
    public function start(?bool $enter = null): void
    {
        $this->begin($enter);
        while ($this->advance()['status'] !== 'complete') {
            // Each step copies one time-boxed batch.
        }
    }

    /**
     * Start provisioning a new demo. By default the demo is published for demo users and the starter
     * stays where they are; a presenter who resets from inside a demo is moved into the new one.
     */
    public function begin(?bool $enter = null): void
    {
        abort_unless($this->canManage(), 403);
        $enter ??= (bool) config('demo-mode.enter_on_start', false) || (session()->has('demo_mode') && ! $this->isVisitor());
        $this->withStorageLock(function () use ($enter): void {
            if (is_string($pending = session('demo_mode_provisioning'))) {
                $this->discardProvisioning($pending);
            }
            $sourceUser = Auth::user();
            if (! $sourceUser instanceof Model) {
                throw new \LogicException('Demo Mode requires an Eloquent authenticatable user model.');
            }
            $source = DB::connection();
            $driver = $this->drivers()->resolve($source);
            $capability = $driver->supports($source)->merge($this->drivers()->schemaSource()->supports($driver, $source));
            if (! $capability->supported()) {
                throw new ProvisioningFailed(implode(' ', $capability->reasons));
            }
            $tables = $this->sandboxTables($source, $sourceUser);
            $token = (string) Str::uuid();
            File::ensureDirectoryExists($this->directory($token), 0700);
            $ref = $driver->ref($token, (string) Auth::id(), $source);
            $this->metadata()->saveProvisioning($token, [
                ...$this->provisioner()->state($ref, $source, $tables), 'owner' => Auth::id(), 'enter' => $enter,
            ]);
            session(['demo_mode_provisioning' => $token]);
        });
    }

    public function provisioning(): ?array
    {
        $token = session('demo_mode_provisioning');
        if (! is_string($token) || ! Str::isUuid($token)) {
            return null;
        }
        $state = $this->metadata()->provisioning($token);

        return $state === null || ! isset($state['ref']) ? null : $this->provisioner()->progress($state);
    }

    /**
     * Run provisioning work for up to the configured step time and report progress.
     */
    public function advance(): array
    {
        $token = session('demo_mode_provisioning');
        abort_unless(is_string($token) && Str::isUuid($token), 404);
        abort_unless($this->canManage(), 403);
        try {
            return $this->withStorageLock(fn (): array => $this->advanceLocked($token));
        } catch (\Throwable $exception) {
            $this->withStorageLock(fn () => $this->discardProvisioning($token));
            throw $exception;
        }
    }

    public function cancel(): void
    {
        if (is_string($token = session('demo_mode_provisioning'))) {
            $this->withStorageLock(fn () => $this->discardProvisioning($token));
        }
    }

    public function retainOnly(string $token): int
    {
        return $this->withStorageLock(fn (): int => $this->removeOtherSandboxes($token));
    }

    private function withStorageLock(\Closure $callback): mixed
    {
        return app(LockProvider::class)->run($callback);
    }

    /**
     * Remove every sandbox except the given one: token directories (files, cache, SQLite) and
     * server-side containers owned by the sandbox's driver.
     */
    private function removeOtherSandboxes(string $token): int
    {
        $keepRef = $this->ref($token);
        $driver = $this->drivers()->for($keepRef);
        if (! $driver->exists($keepRef)) {
            throw new \InvalidArgumentException('The demo sandbox to retain does not exist.');
        }
        $keep = $this->directory($token);
        [$count, $removed] = [0, []];
        foreach (File::directories(config('demo-mode.root')) as $directory) {
            if ($directory !== $keep && Str::isUuid(basename($directory))) {
                if (! File::deleteDirectory($directory)) {
                    throw new \RuntimeException('Could not remove an obsolete demo sandbox.');
                }
                $removed[] = basename($directory);
                $count++;
            }
        }
        if (! $driver instanceof SqliteDriver) {
            $source = DB::connection($keepRef->source);
            $known = array_map(fn (string $removedToken): string => $driver->ref($removedToken, null, $source)->container, $removed);
            foreach ($driver->list() as $container) {
                if ($container !== $keepRef->container) {
                    $driver->drop(new SandboxRef((string) Str::uuid(), $driver->key(), $container, source: $keepRef->source));
                    $count += in_array($container, $known, true) ? 0 : 1;
                }
            }
        }

        return $count;
    }

    private function advanceLocked(string $token): array
    {
        $state = $this->metadata()->provisioning($token);
        // States written before sandbox drivers existed cannot be resumed.
        if ($state === null || ! isset($state['ref'])) {
            throw new ProvisioningFailed('This demo was cancelled or replaced by another demo. Start it again.');
        }
        abort_unless((string) $state['owner'] === (string) Auth::id(), 403);
        $provisioner = $this->provisioner();
        $deadline = microtime(true) + max(0, (float) config('demo-mode.provisioning.step_seconds', 5));
        do {
            $state = $provisioner->step($state);
            $this->metadata()->saveProvisioning($token, $state);
        } while ($state['phase'] !== 'ready' && microtime(true) < $deadline);
        if ($state['phase'] !== 'ready') {
            return $provisioner->progress($state);
        }
        // States saved before background starts existed always entered the demo.
        $enter = (bool) ($state['enter'] ?? true);
        $this->activate($token, $enter);

        return ['status' => 'complete', 'percent' => 100, 'message' => $enter ? 'Demo ready' : 'Demo is live',
            'redirect' => $enter ? route('demo-mode.roles') : null, 'warnings' => $state['warnings'] ?? []];
    }

    private function activate(string $token, bool $enter = true): void
    {
        $userId = Auth::id();
        $userClass = Auth::user()::class;
        $runtime = app(SandboxRuntime::class);
        try {
            $runtime->enter($token);
            // Prepared even when not entering, so the starter can open the demo later.
            $demoUser = $userClass::on('demo')->findOrFail($userId);
            app(ApplicationAdapter::class)->prepare($demoUser);
            if ($enter) {
                $this->notice(session()->has('demo_mode') ? 'refreshed' : 'entered');
                $originalSession = session('demo_mode.original_session', $this->contextSession());
                foreach (array_keys($this->contextSession()) as $key) {
                    session()->forget($key);
                }
                session(['demo_mode' => ['token' => $token, 'owner' => $userId,
                    'original_session' => $originalSession,
                ]]);
                session()->forget(['demo_mode_saved', 'demo_mode_provisioning']);
                app(ApplicationAdapter::class)->clearContext();
                session()->regenerate();
            } else {
                session()->forget(['demo_mode_saved', 'demo_mode_provisioning']);
            }
        } finally {
            $runtime->leave();
        }
        $ref = $this->ref($token);
        // Only replace the saved sandbox after the new copy is fully provisioned.
        $this->persistDemo($enter ? session('demo_mode') : ['token' => $token, 'owner' => $userId], [], $ref);
        $this->metadata()->forgetProvisioning($token);
        $this->removeOtherSandboxes($token);
    }

    private function sandboxTables(Connection $source, Model $sourceUser): array
    {
        $allowed = $this->modelOptions();
        // Saved models that are no longer offered (e.g. their table was excluded) are skipped.
        $models = array_intersect(DemoSetting::query()->first()?->models ?? array_keys($allowed), array_keys($allowed));
        $tables = [...config('demo-mode.required_tables'), $sourceUser->getTable()];
        $adapter = app(ApplicationAdapter::class);
        if ($adapter instanceof RequiredTables) {
            $tables = [...$tables, ...$adapter->requiredTables($sourceUser)];
        }
        foreach ($models as $model) {
            $tables[] = (new $model)->getTable();
        }
        // Copy selected models' many-to-many links when both endpoints are included.
        foreach (array_keys($allowed) as $class) {
            $model = new $class;
            if (! in_array($model->getTable(), $tables, true)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();
                if ($method->getNumberOfRequiredParameters() !== 0 || ! $type instanceof \ReflectionNamedType
                    || ! is_a($type->getName(), BelongsToMany::class, true)) {
                    continue;
                }
                $relation = $model->{$method->getName()}();
                if (in_array($relation->getRelated()->getTable(), $tables, true)) {
                    $tables[] = $relation->getTable();
                }
            }
        }
        $tables = array_values(array_unique($tables));
        $schema = $source->getSchemaBuilder();
        // Include parent tables so selected records retain valid foreign keys.
        for ($index = 0; $index < count($tables); $index++) {
            if (! $schema->hasTable($tables[$index])) {
                throw new \LogicException('Missing source table: '.$tables[$index]);
            }
            foreach ($schema->getForeignKeys($tables[$index]) as $foreignKey) {
                $parent = $foreignKey['foreign_table'];
                if (! in_array($parent, $tables, true)) {
                    $tables[] = $parent;
                }
            }
        }

        return array_values(array_unique(array_diff($tables, config('demo-mode.excluded_tables'))));
    }

    private function discardProvisioning(string $token): void
    {
        session()->forget('demo_mode_provisioning');
        // Never delete the saved demo, even if a stale session still references it.
        if (! Str::isUuid($token) || $token === $this->currentToken()) {
            return;
        }
        $ref = $this->ref($token);
        DB::purge('demo');
        if ($ref->container !== ($this->savedDemo()['ref']['container'] ?? null)) {
            try {
                $this->drivers()->for($ref)->drop($ref);
            } catch (\Throwable $exception) {
                // The directory is still removed; demo-mode:prune drops orphaned containers later.
                report($exception);
            }
        }
        $this->metadata()->forgetProvisioning($token);
        File::deleteDirectory($this->directory($token));
    }

    public function markActive(bool $active): void
    {
        $this->active = $active;
    }

    public function stop(bool $preserve = true): void
    {
        $state = session('demo_mode');
        // A demo user's session never replaces the presenter's saved demo or context.
        if ($state['visitor'] ?? false) {
            session()->forget('demo_mode');

            return;
        }
        if ($preserve && is_array($state)) {
            session(['demo_mode_saved' => [
                'token' => $state['token'], 'owner' => $state['owner'],
                'context' => $this->contextSession(),
            ]]);
            $this->withStorageLock(function () use ($state): void {
                if (Str::isUuid($state['token'] ?? '') && $this->exists($state['token'])) {
                    $this->persistDemo($state, $this->contextSession(), $this->ref($state['token']));
                }
            });
        }
        $original = session('demo_mode.original_session', []);
        foreach (array_keys($this->contextSession()) as $key) {
            session()->forget($key);
        }
        session()->forget('demo_mode');
        app(ApplicationAdapter::class)->clearContext();
        session()->put($original);
        session()->regenerate();
        $this->notice('exited');
    }

    private function contextSession(): array
    {
        return array_filter(session()->all(), function ($key): bool {
            foreach (config('demo-mode.session_keys', []) as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }

            return false;
        }, ARRAY_FILTER_USE_KEY);
    }
}
