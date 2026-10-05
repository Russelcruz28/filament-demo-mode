<?php

namespace DemoMode;

use Closure;
use DemoMode\Http\DemoMiddleware;
use DemoMode\Resources\DemoSettingResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class DemoModePlugin implements Plugin
{
    private bool $management = true;

    private ?string $adapter = null;

    private array $callbacks = [];

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'demo-mode';
    }

    public function register(Panel $panel): void
    {
        if ($this->management) {
            $panel->resources([DemoSettingResource::class]);
            config(['demo-mode.panel' => $panel->getId()]);
            if ($this->adapter) {
                config(['demo-mode.adapter' => $this->adapter]);
            }
            foreach ($this->callbacks as $name => $callback) {
                app(DemoModeOptions::class)->{$name} = $callback;
            }
        }
        $panel->middleware([DemoMiddleware::class], isPersistent: true);
    }

    public function management(bool $enabled = true): static
    {
        $this->management = $enabled;

        return $this;
    }

    public function hasManagement(): bool
    {
        return $this->management;
    }

    public function adapter(string $adapter): static
    {
        $this->adapter = $adapter;

        return $this;
    }

    public function authorizeUsing(Closure $callback): static
    {
        $this->callbacks['authorize'] = $callback;

        return $this;
    }

    public function prepareUsing(Closure $callback): static
    {
        $this->callbacks['prepare'] = $callback;

        return $this;
    }

    public function rolesUsing(Closure $callback): static
    {
        $this->callbacks['roles'] = $callback;

        return $this;
    }

    public function switchRoleUsing(Closure $callback): static
    {
        $this->callbacks['switchRole'] = $callback;

        return $this;
    }

    public function synchronizeRoleUsing(Closure $callback): static
    {
        $this->callbacks['synchronizeRole'] = $callback;

        return $this;
    }

    public function exitUsing(Closure $callback): static
    {
        $this->callbacks['exit'] = $callback;

        return $this;
    }

    public function boot(Panel $panel): void {}
}
