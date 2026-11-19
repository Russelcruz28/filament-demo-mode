<?php

namespace DemoMode;

use Closure;
use DemoMode\Http\DemoMiddleware;
use DemoMode\Resources\DemoSettingResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;

class DemoModePlugin implements Plugin
{
    private bool $management = true;

    private ?string $adapter = null;

    private ?string $sandboxDriver = null;

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
            if ($this->sandboxDriver) {
                config(['demo-mode.sandbox.driver' => $this->sandboxDriver]);
            }
            foreach ($this->callbacks as $name => $callback) {
                app(DemoModeOptions::class)->{$name} = $callback;
            }
        }
        // Demo mode, including its banner, badge and progress modal, is limited to panels that register the plugin.
        $panel->middleware([DemoMiddleware::class], isPersistent: true)
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('demo-mode::banner'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('demo-mode::topbar-badge'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('demo-mode::provisioning-modal'));
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

    /** Use a custom sandbox driver class, or a built-in driver key such as "pgsql-schema". */
    public function sandboxDriver(string $driver): static
    {
        $this->sandboxDriver = $driver;

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

    /** Tag demo users by rule (e.g. a role) in addition to the users selected in the settings. */
    public function demoUsersUsing(Closure $callback): static
    {
        $this->callbacks['demoUser'] = $callback;

        return $this;
    }

    /**
     * Group the models offered under "Starting data". Return a label (or enum) for a model class,
     * or null to fall back to its resource's navigation group.
     */
    public function groupModelsUsing(Closure $callback): static
    {
        $this->callbacks['modelGroup'] = $callback;

        return $this;
    }

    public function exitUsing(Closure $callback): static
    {
        $this->callbacks['exit'] = $callback;

        return $this;
    }

    public function boot(Panel $panel): void {}
}
