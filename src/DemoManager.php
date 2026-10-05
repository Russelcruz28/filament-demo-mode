<?php

namespace DemoMode;

use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\Contracts\RequiredTables;
use DemoMode\Models\DemoSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Artisan;
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

    public function canRestore(): bool
    {
        $saved = $this->savedDemo();
        if (! $this->canManage() || session()->has('demo_mode') || ! is_array($saved)
            || (string) ($saved['owner'] ?? '') !== (string) Auth::id()
            || ! Str::isUuid($saved['token'] ?? '')) {
            return false;
        }

        $directory = $this->directory($saved['token']);

        return File::exists($directory.'/database.sqlite');
    }

    private function savedDemo(): ?array
    {
        $file = config('demo-mode.root').'/current.json';
        if (File::exists($file)) {
            try {
                $saved = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);

                return is_array($saved) ? $saved : null;
            } catch (\JsonException) {
                return null;
            }
        }

        return session('demo_mode_saved');
    }

    public function currentToken(): ?string
    {
        $token = $this->savedDemo()['token'] ?? null;

        return is_string($token) && Str::isUuid($token) ? $token : null;
    }

    private function persistDemo(array $state, array $context): void
    {
        $saved = ['token' => $state['token'], 'owner' => $state['owner'], 'context' => $context];
        File::replace(config('demo-mode.root').'/current.json', json_encode($saved, JSON_THROW_ON_ERROR));
        File::chmod(config('demo-mode.root').'/current.json', 0600);
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
        $tables = DB::connection()->getSchemaBuilder()->getTableListing(schemaQualified: false);
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

    public function directory(string $token): string
    {
        if (! Str::isUuid($token)) {
            throw new \InvalidArgumentException('Invalid demo sandbox token.');
        }

        return config('demo-mode.root').'/'.$token;
    }

    public function configure(string $token): void
    {
        DB::purge('demo');
        config(['database.connections.demo' => [
            'driver' => 'sqlite', 'database' => $this->directory($token).'/database.sqlite',
            'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000,
        ]]);
    }

    public function start(): void
    {
        abort_unless($this->canManage(), 403);
        $this->withStorageLock(fn () => $this->provision());
    }

    public function retainOnly(string $token): int
    {
        return $this->withStorageLock(fn (): int => $this->removeOtherSandboxes($token));
    }

    private function withStorageLock(\Closure $callback): mixed
    {
        File::ensureDirectoryExists(config('demo-mode.root'), 0700);
        $lock = fopen(config('demo-mode.root').'/.sandbox.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the demo storage lock.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock demo storage.');
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function removeOtherSandboxes(string $token): int
    {
        $keep = $this->directory($token);
        if (! File::exists($keep.'/database.sqlite')) {
            throw new \InvalidArgumentException('The demo sandbox to retain does not exist.');
        }
        $count = 0;
        foreach (File::directories(config('demo-mode.root')) as $directory) {
            if ($directory !== $keep && Str::isUuid(basename($directory))) {
                if (! File::deleteDirectory($directory)) {
                    throw new \RuntimeException('Could not remove an obsolete demo sandbox.');
                }
                $count++;
            }
        }

        return $count;
    }

    private function provision(): void
    {
        $sourceName = DB::getDefaultConnection();
        $source = DB::connection($sourceName);
        $userId = Auth::id();
        $sourceUser = Auth::user();
        if (! $sourceUser instanceof Model) {
            throw new \LogicException('Demo Mode requires an Eloquent authenticatable user model.');
        }
        $userClass = $sourceUser::class;
        $models = DemoSetting::query()->first()?->models ?? array_keys($this->modelOptions());
        $allowed = $this->modelOptions();
        $tables = [...config('demo-mode.required_tables'), $sourceUser->getTable()];
        $adapter = app(ApplicationAdapter::class);
        if ($adapter instanceof RequiredTables) {
            $tables = [...$tables, ...$adapter->requiredTables($sourceUser)];
        }
        foreach ($models as $model) {
            if (! isset($allowed[$model])) {
                throw new \InvalidArgumentException('An unavailable model was selected for demo mode.');
            }
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
        $tables = array_values(array_unique(array_diff($tables, config('demo-mode.excluded_tables'))));
        $token = (string) Str::uuid();
        $directory = $this->directory($token);
        File::ensureDirectoryExists($directory, 0700);
        File::put($directory.'/database.sqlite', '');
        $runtime = app(SandboxRuntime::class);
        try {
            $runtime->enter($token);
            if (Artisan::call('migrate', ['--database' => 'demo', '--force' => true]) !== 0) {
                throw new \RuntimeException('Demo schema creation failed.');
            }
            // Migrations can insert defaults. The sandbox must match the selected source data.
            $demo = DB::connection('demo');
            $demo->getSchemaBuilder()->disableForeignKeyConstraints();
            $this->active = false;
            $demo->transaction(function () use ($source, $demo, $tables): void {
                foreach ($demo->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
                    if ($table !== 'migrations') {
                        $demo->table($table)->delete();
                    }
                }
                foreach ($tables as $table) {
                    $columns = $demo->getSchemaBuilder()->getColumnListing($table);
                    foreach ($source->table($table)->cursor() as $row) {
                        $demo->table($table)->insert(array_intersect_key((array) $row, array_flip($columns)));
                    }
                }
            });
            $demo->getSchemaBuilder()->enableForeignKeyConstraints();
            if ($demo->select('PRAGMA foreign_key_check') !== []) {
                throw new \LogicException('Demo data has missing relationships. Select the related models and try again.');
            }
            $demoUser = $userClass::on('demo')->findOrFail($userId);
            $this->active = true;
            app(ApplicationAdapter::class)->prepare($demoUser);
            $originalSession = session('demo_mode.original_session', $this->contextSession());
            foreach (array_keys($this->contextSession()) as $key) {
                session()->forget($key);
            }
            session(['demo_mode' => ['token' => $token, 'owner' => $userId,
                'original_session' => $originalSession,
            ]]);
            session()->forget('demo_mode_saved');
            app(ApplicationAdapter::class)->clearContext();
            session()->regenerate();
        } catch (\Throwable $exception) {
            DB::purge('demo');
            File::deleteDirectory($directory);
            throw $exception;
        } finally {
            $runtime->leave();
        }
        // Only replace the saved sandbox after the new copy is fully provisioned.
        $this->removeOtherSandboxes($token);
        $this->persistDemo(session('demo_mode'), []);
    }

    public function markActive(bool $active): void
    {
        $this->active = $active;
    }

    public function stop(bool $preserve = true): void
    {
        $state = session('demo_mode');
        if ($preserve && is_array($state)) {
            session(['demo_mode_saved' => [
                'token' => $state['token'], 'owner' => $state['owner'],
                'context' => $this->contextSession(),
            ]]);
            $this->withStorageLock(function () use ($state): void {
                if (Str::isUuid($state['token'] ?? '') && File::exists($this->directory($state['token']).'/database.sqlite')) {
                    $this->persistDemo($state, $this->contextSession());
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
