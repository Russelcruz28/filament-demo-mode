<?php

namespace DemoMode;

use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

class SandboxRuntime
{
    private array $configuration = [];

    private array $facades = [];

    public function enter(string $token): void
    {
        $this->configuration = config()->all();
        foreach ([Cache::class, Mail::class, Queue::class, Bus::class, Http::class, Redis::class, Storage::class] as $facade) {
            $this->facades[$facade] = $facade::getFacadeRoot();
        }
        $manager = app(DemoManager::class);
        $manager->configure($token);
        $root = $manager->directory($token);
        DB::setDefaultConnection('demo');
        config([
            'cache.default' => 'demo',
            'cache.stores.demo' => ['driver' => 'file', 'path' => $root.'/cache', 'lock_path' => $root.'/cache'],
            'permission.cache.store' => 'demo',
        ]);
        config(config('demo-mode.sandbox_config', []));
        Cache::swap(new CacheManager(app()));
        Mail::fake();
        Queue::fake();
        Bus::fake();
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
        $this->refreshPermissions();
        $manager->markActive(true);
    }

    public function leave(): void
    {
        app(DemoManager::class)->markActive(false);
        if ($this->configuration === []) {
            return;
        }
        config()->set($this->configuration);
        DB::setDefaultConnection($this->configuration['database']['default']);
        DB::purge('demo');
        foreach ($this->facades as $facade => $root) {
            $facade::swap($root);
        }
        $this->refreshPermissions();
        $this->configuration = [];
        $this->facades = [];
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
