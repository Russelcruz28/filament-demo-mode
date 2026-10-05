<?php

namespace DemoMode\Adapters;

use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\Contracts\RequiredTables;
use DemoMode\Contracts\RoleSwitcher;
use DemoMode\DemoModeOptions;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DefaultApplicationAdapter implements ApplicationAdapter, RequiredTables, RoleSwitcher
{
    public function __construct(private DemoModeOptions $options) {}

    public function canManage(?Authenticatable $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($this->options->authorize) {
            return (bool) ($this->options->authorize)($user);
        }

        return method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(config('demo-mode.authorized_roles', ['super_admin']));
    }

    public function prepare(Authenticatable $user): void
    {
        if ($this->options->prepare) {
            ($this->options->prepare)($user);

            return;
        }
        if ($this->panel()->hasTenancy() && (! $this->options->roles || ! $this->options->switchRole)) {
            throw new \LogicException('Tenant panels require a demo adapter or rolesUsing() and switchRoleUsing() callbacks.');
        }
        if (method_exists($user, 'syncRoles')) {
            $user->syncRoles($this->roleRecords($user));
            $user->syncPermissions([]);
        }
    }

    public function requiredTables(Authenticatable $user): array
    {
        $tables = [];
        foreach (['roles', 'permissions'] as $name) {
            if (! method_exists($user, $name) || ! ($relation = $user->{$name}()) instanceof BelongsToMany) {
                continue;
            }
            $tables[] = $relation->getTable();
            $tables[] = $relation->getRelated()->getTable();
            if ($name === 'roles' && method_exists($relation->getRelated(), 'permissions')) {
                $permissions = $relation->getRelated()->permissions();
                if ($permissions instanceof BelongsToMany) {
                    $tables[] = $permissions->getTable();
                    $tables[] = $permissions->getRelated()->getTable();
                }
            }
        }

        return array_values(array_unique($tables));
    }

    public function roles(Authenticatable $user): array
    {
        if ($this->options->roles) {
            return ($this->options->roles)($user);
        }
        $roles = $this->roleRecords($user);
        if ($roles->isEmpty()) {
            return [['id' => 'dashboard', 'label' => 'Dashboard', 'group' => 'Demo', 'selected' => true]];
        }

        return $roles->map(fn ($role): array => [
            'id' => (string) $role->getKey(), 'label' => Str::headline($role->name),
            'group' => 'Roles', 'selected' => session('demo_role') === (string) $role->getKey(),
        ])->values()->all();
    }

    public function switchRole(Authenticatable $user, string $id): string
    {
        abort_unless(collect($this->roles($user))->contains(fn ($role) => (string) $role['id'] === $id), 403);
        if ($this->options->switchRole) {
            return ($this->options->switchRole)($user, $id);
        }
        session(['demo_role' => $id]);
        $this->synchronizeRole($user);

        return $this->panel()->getUrl();
    }

    public function synchronizeRole(Authenticatable $user): void
    {
        if ($this->options->synchronizeRole) {
            ($this->options->synchronizeRole)($user);

            return;
        }
        if (! method_exists($user, 'syncRoles')) {
            return;
        }
        $roles = $this->roleRecords($user);
        $role = filled(session('demo_role'))
            ? $roles->first(fn ($role) => (string) $role->getKey() === session('demo_role'))
            : ($roles->first(fn ($role) => in_array($role->name, config('demo-mode.authorized_roles', ['super_admin']), true)) ?? $roles->first());
        $user->syncRoles($role ? [$role] : []);
        $user->syncPermissions([]);
    }

    public function clearContext(): void
    {
        session()->forget('demo_role');
    }

    public function destination(): string
    {
        return $this->options->exit ? ($this->options->exit)() : $this->panel()->getUrl();
    }

    private function panel(): Panel
    {
        return Filament::getPanel(config('demo-mode.panel', 'admin'))
            ?? throw new \LogicException('Register DemoModePlugin on the configured management panel before starting a demo.');
    }

    private function roleRecords(Authenticatable $user): Collection
    {
        if (! method_exists($user, 'roles') || ! ($relation = $user->roles()) instanceof BelongsToMany) {
            return collect();
        }
        $query = $relation->getRelated()->newQuery();
        if ($query->getModel()->getConnection()->getSchemaBuilder()->hasColumn($query->getModel()->getTable(), 'guard_name')) {
            $query->where('guard_name', config('demo-mode.role_guard', 'web'));
        }

        return $query->get();
    }
}
