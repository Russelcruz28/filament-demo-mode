<?php

namespace DemoMode;

use DemoMode\Sandbox\ConnectionPolicy;
use DemoMode\Sandbox\SandboxDrivers;
use DemoMode\Sandbox\SandboxRef;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Connectors\SyncConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Pulse\Pulse;
use Laravel\Telescope\Telescope;
use Spatie\Permission\PermissionRegistrar;

class SandboxRuntime
{
    private array $configuration = [];

    private array $facades = [];

    private ?string $token = null;

    /** @var array<string, string> */
    private array $policies = [];

    /** @var array<string, Connection|null> Connections replaced by the sandbox connection. */
    private array $aliased = [];

    /** @var array<string, object|null> Container instances replaced by sandbox_bindings. */
    private array $bindings = [];

    /** @var list<string> */
    private array $paused = [];

    public function enter(string $token): void
    {
        $this->enterRef(app(DemoManager::class)->ref($token));
    }

    public function enterRef(SandboxRef $ref): void
    {
        $this->configuration = config()->all();
        foreach ([Cache::class, Mail::class, Queue::class, Bus::class, Http::class, Redis::class, Storage::class] as $facade) {
            $this->facades[$facade] = $facade::getFacadeRoot();
        }
        $manager = app(DemoManager::class);
        app(SandboxDrivers::class)->connect($ref);
        $this->token = $ref->token;
        $root = $manager->directory($ref->token);
        $this->policies = app(ConnectionPolicy::class)->resolve($ref->source ?? config('database.default'));
        DB::setDefaultConnection('demo');
        $this->aliasConnections();
        $this->configureCache($ref->token, $root);
        config(config('demo-mode.sandbox_config', []));
        Cache::swap($cache = new CacheManager(app()));
        // Resolve the store before Redis is replaced, so a Redis-backed prefixed store keeps its client.
        $cache->store();
        Mail::fake();
        $this->configureQueue();
        Http::swap((new Factory)->preventStrayRequests());
        Redis::swap(new SandboxRedis);
        // Every named disk is redirected, including explicitly selected cloud disks.
        foreach (array_keys(config('filesystems.disks')) as $name) {
            config(['filesystems.disks.'.$name => [
                'driver' => 'local', 'root' => $root.'/files/'.$name,
                'url' => url('/demo-mode/files/'.$name), 'throw' => true, 'serve' => true,
            ]]);
        }
        Storage::swap(new FilesystemManager(app()));
        foreach (config('demo-mode.sandbox_cache', []) as $key => $value) {
            Cache::put($key, $value);
        }
        $this->swapBindings();
        $this->pauseRecorders();
        $this->refreshPermissions();
        $manager->markActive(true);
    }

    public function leave(): void
    {
        app(DemoManager::class)->markActive(false);
        if ($this->configuration === []) {
            return;
        }
        $this->resumeRecorders();
        $this->restoreBindings();
        config()->set($this->configuration);
        DB::setDefaultConnection($this->configuration['database']['default']);
        foreach ($this->aliased as $name => $connection) {
            $this->swapConnection($name, $connection);
        }
        DB::purge('demo');
        foreach ($this->facades as $facade => $root) {
            $facade::swap($root);
        }
        $this->refreshPermissions();
        [$this->configuration, $this->facades, $this->aliased, $this->policies, $this->token] = [[], [], [], [], null];
    }

    /** The active sandbox token, or null outside the sandbox. */
    public function token(): ?string
    {
        return $this->token;
    }

    public function policy(string $connection): string
    {
        return $this->policies[$connection] ?? ConnectionPolicy::BLOCK;
    }

    /**
     * Connections that point at the source database resolve to the sandbox connection. Their config is
     * replaced too, so a purged and re-created connection still lands in the sandbox.
     */
    private function aliasConnections(): void
    {
        $config = config('database.connections.demo');
        $demo = DB::connection('demo');
        foreach ($this->policies as $name => $policy) {
            if ($policy === ConnectionPolicy::SANDBOX) {
                config(['database.connections.'.$name => $config]);
                $this->aliased[$name] = $this->swapConnection($name, $demo);
            }
        }
    }

    /**
     * Replace a resolved connection without disconnecting it: the source may hold open transactions
     * or be an in-memory database that would be lost on disconnect.
     */
    private function swapConnection(string $name, ?Connection $connection): ?Connection
    {
        return (function () use ($name, $connection): ?Connection {
            $previous = $this->connections[$name] ?? null;
            if ($connection === null) {
                unset($this->connections[$name]);
            } else {
                $this->connections[$name] = $connection;
            }

            return $previous;
        })->call(app('db'));
    }

    private function configureCache(string $token, string $root): void
    {
        if (config('demo-mode.runtime.cache', 'file') === 'prefix') {
            // Same store as production (keeps Redis tags), isolated by key prefix.
            $store = (array) config('cache.stores.'.config('cache.default'), []);
            $prefix = ($store['prefix'] ?? config('cache.prefix')).'demo:'.$token.':';
            config(['cache.default' => 'demo', 'cache.stores.demo' => [...$store, 'prefix' => $prefix]]);
        } else {
            config([
                'cache.default' => 'demo',
                'cache.stores.demo' => ['driver' => 'file', 'path' => $root.'/cache', 'lock_path' => $root.'/cache'],
            ]);
        }
        config(['permission.cache.store' => 'demo']);
    }

    private function configureQueue(): void
    {
        if (config('demo-mode.runtime.queue', 'fake') !== 'sync') {
            Queue::fake();
            Bus::fake();

            return;
        }
        // Run jobs inline inside the sandbox; no queue connection can reach a real broker.
        foreach (array_keys((array) config('queue.connections', [])) as $name) {
            config(['queue.connections.'.$name => ['driver' => 'sync']]);
        }
        config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);
        $queue = new QueueManager(app());
        $queue->addConnector('sync', fn () => new SyncConnector);
        Queue::swap($queue);
    }

    private function swapBindings(): void
    {
        foreach ((array) config('demo-mode.sandbox_bindings', []) as $abstract => $replacement) {
            $this->bindings[$abstract] = (fn () => $this->instances[$this->getAlias($abstract)] ?? null)->call(app());
            app()->instance($abstract, match (true) {
                $replacement instanceof \Closure => $replacement(app()),
                is_string($replacement) => app()->make($replacement),
                default => $replacement,
            });
            Facade::clearResolvedInstance($abstract);
        }
    }

    private function restoreBindings(): void
    {
        foreach ($this->bindings as $abstract => $previous) {
            app()->forgetInstance($abstract);
            if ($previous !== null) {
                app()->instance($abstract, $previous);
            }
            Facade::clearResolvedInstance($abstract);
        }
        $this->bindings = [];
    }

    /** Telescope and Pulse write on terminate, after the sandbox is left; demo activity is not recorded. */
    private function pauseRecorders(): void
    {
        $pause = (array) config('demo-mode.runtime.pause', []);
        if (in_array('telescope', $pause, true) && class_exists(Telescope::class)
            && method_exists(Telescope::class, 'isRecording') && Telescope::isRecording()) {
            Telescope::stopRecording();
            $this->paused[] = 'telescope';
        }
        if (in_array('pulse', $pause, true) && class_exists(Pulse::class) && app()->bound(Pulse::class)) {
            $pulse = app(Pulse::class);
            if (method_exists($pulse, 'stopRecording')) {
                $pulse->stopRecording();
                $this->paused[] = 'pulse';
            }
        }
    }

    private function resumeRecorders(): void
    {
        if (in_array('telescope', $this->paused, true)) {
            Telescope::startRecording();
        }
        if (in_array('pulse', $this->paused, true)) {
            app(Pulse::class)->startRecording();
        }
        $this->paused = [];
    }

    private function refreshPermissions(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            $registrar = app(PermissionRegistrar::class);
            $registrar->clearPermissionsCollection();
            $registrar->initializeCache();
        }
    }
}
