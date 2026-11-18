# Changelog

## Unreleased

- Demo users: tag accounts in the Demo Mode settings (or with `demoUsersUsing()`) to make
  them always sign in to the saved demo, with no access to production, role switching,
  Reset or Exit. Requires `php artisan migrate` (adds `demo_settings.users`).

- Sandbox drivers: `sqlite` (unchanged default), `pgsql-schema`, `pgsql-database` and
  `mysql-database`, selected with `sandbox.driver` (`auto` follows the source engine) or
  `DemoModePlugin::make()->sandboxDriver()`. Custom drivers implement `SandboxDriver`.
- Schema sources: `migrations` (default), `dump` (Laravel schema dump) and `clone`
  (live structure, including PostgreSQL functions, sequences, triggers and views, and
  MySQL routines, triggers and views).
- Provisioning phases `prepare → schema → strip → copy → finalize → verify`: foreign keys
  are dropped and re-validated, triggers disabled or recreated, sequences reset; keyset
  paging for composite keys; set-based copies on the same server and `COPY` streaming.
- Source columns missing from the sandbox now fail provisioning instead of being dropped
  silently (`sandbox.on_column_mismatch = warn` to continue).
- Connections to the source database resolve to the sandbox while a demo is active
  (fixes migrations that override `getConnection()`, such as Telescope's). Other
  connections stay blocked unless listed in `connections.allow`. **Behaviour change:**
  `DB::connection('<source name>')` now reaches the sandbox instead of throwing.
- Runtime options: `runtime.cache = prefix`, `runtime.queue = sync`, `sandbox_bindings`,
  Telescope/Pulse paused during demo requests, `DemoMode::active()` facade and
  `@demomode` Blade directive.
- Shared metadata (`metadata = database`, `demo_sandboxes` table) and locks
  (`lock = cache | database`) for multi-server deployments. Run `php artisan migrate`.
- `demo-mode:doctor` checks driver capabilities, privileges, the PostgreSQL dedicated
  role and migrations, exits non-zero when the setup cannot work, and `--provision`
  builds and drops a throwaway sandbox.
- `demo-mode:install` publishes `sandbox.driver = auto` for new installs.
- The recovery page also handles PostgreSQL and MySQL missing table/column errors.

## 0.1.7 - 2026-11-06

- Show an actionable recovery page when a saved demo is missing a table or column, with reset and exit actions and support for Livewire and JSON requests.

## 0.1.6 - 2026-10-09

- Saved model selections that are no longer offered (for example after their
  table is added to `excluded_tables`) are dropped instead of failing form
  validation with "The selected copy starting data from is invalid" and
  blocking Start Demo.

## 0.1.5 - 2026-10-09

- Start/Reset shows provisioning progress in a modal on the current panel page
  instead of a separate page. `/demo-mode/provisioning` now redirects to the
  panel, where the modal resumes any pending copy.

## 0.1.4 - 2026-10-09

- Stream rows while copying demo data so tables with wide rows (JSON, long
  text) no longer exhaust PHP memory mid-copy.
- Progress page retries longer with backoff, shows the HTTP status on failure
  and can be reloaded to resume.

## 0.1.3 - 2026-10-09

- Start/Reset now shows a progress page and copies data in chunks across short
  requests, so large datasets no longer hit request timeouts. Tune with
  `provisioning.chunk_size` and `provisioning.step_seconds`. The copy can be
  cancelled and failures keep the previously saved demo.

## 0.1.2 - 2026-10-07

- Support Laravel 13 (Orchestra Testbench 11) alongside Laravel 12.
- CI: test the Laravel 12/13 and Filament 4/5 matrix, run `composer audit`,
  pin GitHub Actions to commit SHAs and restrict the token to read-only.
- Add Dependabot for Composer and GitHub Actions with a 7-day cooldown.

## 0.1.1 - 2026-10-05

- Support Filament 4 / Livewire 3 and Filament 5 / Livewire 4.
- Test both Filament majors in CI, including Livewire demo snapshot validation.

## 0.1.0 - 2026-10-05

- Persistent SQLite sandbox, model selection and production-context isolation.
- One installation-wide sandbox, indefinite restore and standalone role picker.
- Filament registration and Laravel provider auto-discovery.
- Default Spatie integration, callbacks and custom application adapters.
- Install/doctor commands, publishable configuration and views.
- Standalone tests and CI for Laravel 12 / Filament 4.
