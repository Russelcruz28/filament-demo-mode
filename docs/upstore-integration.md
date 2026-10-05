# UPSTORE Integration

A Laravel 12 / Filament 4 and 5 plugin that retains one installation-wide private SQLite
sandbox. Production records are read when the sandbox starts. All subsequent
demo database writes use that sandbox, including models omitted from the copy.

## Using It in UPSTORE

Deploy the code, regenerate Composer autoloading, and run the package migration:

```sh
composer install
php artisan migrate --path=packages/demo-mode/database/migrations --force
```

PHP needs `pdo_sqlite`, and `storage/app/demo-mode` must be writable. Existing
application migrations must also support SQLite. This application's migrations
are exercised by the demo tests.

1. Sign in as Super Admin and open **System > Demo Mode**.
2. Edit the configuration and select the models whose starting records you want
   copied. Save before starting the demo.
3. Select **Start Demo**, then pick a role using Demo Mode's own switcher.
4. Use **Switch role**, **Reset demo**, or **Exit demo** in the demo banner.

Authentication, roles, permissions, offices, and foreign-key parent tables are
always included. Typed many-to-many relationships are copied when both endpoint
tables are included. Unselected models start empty but remain writable in demo.
Models with explicit database connections are omitted from the selector; such
connections are blocked during demo requests. Reset creates a new copy of the
currently selected source data and replaces the saved sandbox for this installation.

The application's adapter grants the presenter all supported roles automatically
and creates sandbox office-role contexts only in their profile office. Other
office memberships are removed from the sandbox presenter. Super Admin keeps its
System context. A profile office is required to start a demo. Missing supported
roles are created only in the sandbox, and existing custom web roles are included.
Only the selected role's permissions apply during demo requests.
Demo Mode owns a standalone role switcher at `/demo-mode/roles`; it does not
modify or use the application's sign-in role chooser for demo navigation.
Exiting persists the current sandbox and role/session context in a private
`current.json` metadata file. Use **Restore Demo** in the Demo Mode resource to
continue with the same database, uploads, and progress. Restoration requires the
same signed-in administrator, but works after logout or loss of the browser
session. Demos have no expiry. Start Demo and Reset Demo replace the saved sandbox; Start Demo asks
for confirmation when a saved demo exists. Production session state is restored
on every exit and captured again on every restore.
Production role assignments never change. Production carts, workflow selections,
and active role are saved and restored on exit. Livewire snapshots carry a signed
sandbox token, and mode transitions rotate the session and CSRF token, preventing
old forms from submitting into another mode.

This application's `Approvable` integration permits the sandbox presenter to
approve or return their own records when `allow_presenter_self_approval` is enabled
in `config/demo-mode.php`. Switching roles retains the same user identity, so this
demo-only exception allows presenting a complete workflow. Approver matching,
office scope, step order, and repeat-action checks still apply. Production
self-approval rules and workflow definitions are unchanged.

## Configuration

Edit `config/demo-mode.php` to configure model discovery or provide an explicit
`models` list of Eloquent class names. `required_tables`, `excluded_tables`,
`session_keys` and `root` customize copying and session isolation.
Set `DEMO_MODE_ENABLED=false` to disable starting/restoring demos and end active
demo sessions on their next request without deleting the saved sandbox.

Obsolete copies can be removed manually without expiring the current demo:

```sh
php artisan demo-mode:prune
```

There is no scheduled expiry or cleanup. One current sandbox is retained for this installation. A successful
Start Demo or Reset Demo deletes older sandbox copies, including demos from other
browser sessions; those sessions can no longer use or restore the old copy.
Exit and Restore reuse the current sandbox. Failed setup leaves the previous
sandbox intact. Provisioning is serialized with a local filesystem lock.
The current sandbox remains private until explicitly replaced or deleted.

## Reusing the Plugin

The package code has no dependency on UPSTORE models. `app/Demo/ApplicationAdapter.php`
contains the application-specific role integration.

Implement the optional `DemoMode\Contracts\RoleSwitcher` interface on the adapter
to populate the standalone demo role page. `roles()` returns role IDs, labels,
group labels, and the selected state. `switchRole()` must authorize the selection,
apply the application's role/context, and return the dashboard destination.
The package routes, view, persistence, and middleware do not reference app models
or a host role-selection controller.

In another Laravel application, install this directory as a Composer path
package named `russelcruz28/filament-demo-mode`. Its provider is auto-discovered. Publish
the defaults and implement `DemoMode\Contracts\ApplicationAdapter`:

```sh
php artisan vendor:publish --tag=demo-mode-config
php artisan migrate
```

Set `demo-mode.adapter` to your adapter class. Implement authorization in
`canManage`, sandbox-only assignments in `prepare`, selected-role permissions in
`synchronizeRole`, session context cleanup in `clearContext`, and the role picker
URL in `destination`. Specify authentication and permission pivot tables in
`required_tables`, and application session-key prefixes in `session_keys`.

Register the resource on your admin panel:

```php
->plugins([\DemoMode\DemoModePlugin::make()])
```

The plugin registers persistent demo middleware for its panel. Also add
`DemoMode\Http\DemoMiddleware::class` to any other panel's middleware list.
Ordinary `web` routes and Livewire updates are covered by the provider. When a
tenant role changes inside a request, call your adapter's `synchronizeRole` again
after updating that role. UPSTORE does this in `SetActiveRoleFromTenant`.

## Runtime Boundaries

- Eloquent and query-builder writes use the private database. Other Laravel
  database connections are blocked during active demo requests.
- All configured Laravel filesystem disks use private sandbox directories.
  New files are served through a session-protected route. Existing production
  upload files are not copied, so those images and attachments may be absent.
- Cache and document-number counters are private to the sandbox.
- Mail and queued jobs are suppressed. Direct database notifications can still
  be saved inside the sandbox; external notification channels are blocked.
- Laravel HTTP-client requests are blocked, and UPSTORE's Kafka notification
  publisher is disabled. Workflows depending on external calls or queued jobs
  will need sandbox-specific adapters to complete those steps.
- Non-web API routes, raw PDO, direct filesystem operations, and external SDKs
  are outside these Laravel integrations. Integrate those explicitly before
  extending demo workflows to use them.

Sandbox startup is synchronous and copies selected rows. Select a smaller set
for large datasets. Copied records retain source values; this is isolation, not
anonymization. The storage root must be private. Multi-server deployments need
shared sandbox storage or sticky routing so subsequent requests find the same
database.

## Verification

```sh
php artisan test tests/Feature/DemoModeTest.php
```
