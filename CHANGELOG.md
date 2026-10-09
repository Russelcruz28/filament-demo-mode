# Changelog

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
