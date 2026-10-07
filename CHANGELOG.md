# Changelog

## Unreleased

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
