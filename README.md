# Filament Demo Mode

<div class="filament-hidden">

![Filament Demo Mode](https://raw.githubusercontent.com/Russelcruz28/filament-demo-mode/main/art/thumbnail.jpg)

</div>

A Laravel 12 / Filament 4 and 5 plugin for persistent SQLite demo sandboxes and a
separate role switcher. Select starting data, present workflows, exit, and
restore the same sandbox later without expiry.

[![Packagist Version](https://img.shields.io/packagist/v/russelcruz28/filament-demo-mode)](https://packagist.org/packages/russelcruz28/filament-demo-mode)
[![Tests](https://github.com/Russelcruz28/filament-demo-mode/actions/workflows/tests.yml/badge.svg)](https://github.com/Russelcruz28/filament-demo-mode/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/russelcruz28/filament-demo-mode)](LICENSE)

## Installation

Requires PHP 8.2+, PDO SQLite, Laravel 12, Filament 4 or 5, an Eloquent authenticatable
user and application migrations compatible with SQLite.

Filament 4 uses Livewire 3; Filament 5 uses Livewire 4. The same plugin
registration and configuration work with both versions.

```sh
composer require russelcruz28/filament-demo-mode:^0.1
php artisan demo-mode:install --migrate
```

The provider is auto-discovered. Installation publishes configuration without
overwriting an existing file. `--migrate` runs only the package settings migration;
omit it to manage migrations through your normal deployment process.

Register on your management panel:

```php
use DemoMode\DemoModePlugin;

->plugins([DemoModePlugin::make()])
```

With Spatie Laravel Permission, the default adapter authorizes `super_admin`,
discovers roles and permission tables, and applies one selected role at a time
to the copied presenter. Production assignments are unchanged. Adjust
`authorized_roles` and `role_guard` in `config/demo-mode.php` as needed.

Without Spatie, provide your own authorization policy:

```php
DemoModePlugin::make()->authorizeUsing(fn ($user) => $user->is_admin)
```

Authorization denies access by default. Without a role integration the switcher
offers Dashboard. Run `php artisan demo-mode:doctor`, open **Demo Mode**, save
your model selection and start. The plugin owns `/demo-mode/roles`; your existing
role chooser needs no changes.

## Custom Role Systems

Callbacks run against the sandbox user, except authorization, which checks the
production user:

```php
DemoModePlugin::make()
    ->authorizeUsing(fn ($user) => $user->is_admin)
    ->prepareUsing(function ($user): void {
        // Create sandbox-only memberships required by your application.
    })
    ->rolesUsing(fn ($user) => [
        ['id' => 'sales-manager', 'label' => 'Sales Manager',
            'group' => 'Sales', 'selected' => session('demo_role') === 'sales-manager'],
    ])
    ->switchRoleUsing(function ($user, string $id): string {
        session(['demo_role' => $id]);
        // Apply the selected role through your sandbox role service.
        return '/admin';
    })
    ->synchronizeRoleUsing(function ($user): void {
        // Reapply the selected role on subsequent demo requests.
    })
    ->exitUsing(fn () => '/admin');
```

Submitted roles must exist in `rolesUsing()` before the switch callback runs.
Callbacks must enforce application-specific team/tenant rules. Include custom
context keys in `session_keys` and supporting tables in `required_tables`.
Tenant panels require an explicit integration; their policies cannot be inferred.

Complex applications can implement `ApplicationAdapter` and `RoleSwitcher` in
`DemoMode\Contracts`, then use `->adapter(YourAdapter::class)` or configure
`adapter`. Optionally implement `RequiredTables` to discover dependency tables.
Application-specific models and policies remain in your adapter, not in this
package.

Use exactly one management panel. Register
`DemoModePlugin::make()->management(false)` on other panels to retain middleware
without adding another resource or replacing management configuration.

## Configuration

- `models`: explicit Eloquent class list; otherwise recursive discovery under
  `model_path` / `model_namespace`. Explicit-connection models are omitted.
- `required_tables`: always-copy tables; user/auth tables and foreign-key parents
  are discovered automatically. Unselected tables start empty but stay writable.
- `session_keys`: prefixes of context keys saved/restored across mode transitions.
  Include application carts, role selections and team context where needed.
- `sandbox_config` / `sandbox_cache`: application-specific overrides and private
  cache markers, such as disabling an external event publisher.
- `root`: private writable storage, optionally set using `DEMO_MODE_ROOT`.
- `enabled`: disable starting/restoring without deleting the saved database.

## Persistence

One current sandbox is retained per installation. Exit preserves its database,
uploads and context. Restore requires the same authorized owner and works after
logout or browser-session loss. There is no expiry or scheduled cleanup.
Successful Start/Reset replaces the saved sandbox, including copies used by
other browsers. Failed provisioning preserves the previous copy.

Data lives in `<root>/<uuid>/database.sqlite`. Private `current.json` metadata
identifies the owner and saved context. Never expose this directory publicly.
Multi-server deployments need shared storage or sticky routing. SQLite demos are
not intended for high-concurrency workloads.

## Isolation Boundaries

Laravel database operations switch to SQLite; other Laravel connections are
blocked during active runtime. Disks, cache and supported Redis operations are
redirected. Mail, queued jobs and external notification channels are suppressed.
Laravel HTTP requests reject stray calls. Production login and session persistence
still use the source database outside active demo runtime. Livewire mode tokens
reject stale submissions.

This is **application-level isolation, not a security boundary**. Raw PDO,
external SDKs, direct file calls, SQL dialect-specific queries, cached service
instances, non-web APIs and application policies need explicit review. Long-lived
workers such as Octane have not been validated. See [Security](SECURITY.md).
Copied data is not anonymized. Production attachments are not copied. Workflows
requiring external services or queued jobs need sandbox replacements. Startup
is synchronous, so select limited datasets for large applications.

## Development

Run inside this directory:

```sh
composer install
composer test
composer lint
composer validate --strict
```

Standalone Orchestra Testbench tests do not depend on a host application. CI targets PHP
8.2, 8.3 and 8.4 with Laravel 12 and both Filament 4 and 5.

To test a specific Filament major locally:

```sh
composer update --with 'filament/filament:^4.0' --prefer-dist
composer test
composer update --with 'filament/filament:^5.0' --prefer-dist
composer test
```
