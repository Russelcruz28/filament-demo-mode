# Filament Plugin Listing

Plugins are submitted through the [Filament author portal](https://filamentphp.com/author)
(sign in with GitHub). The old pull-request flow on `filamentphp/filamentphp.com`
is archived; see the [legacy-site notice](https://github.com/filamentphp/legacy-site).

## Before Submitting

- [x] Public repository: https://github.com/Russelcruz28/filament-demo-mode
- [x] Packagist package: https://packagist.org/packages/russelcruz28/filament-demo-mode
- [ ] Packagist lists the latest tag (`v0.1.1`, Filament 5 support). If it does not,
      click **Update** on the Packagist package page and enable the GitHub hook
      so future tags sync automatically.
- [ ] Clean install verified in a fresh Laravel 12 app on Filament 4 and on Filament 5.
- [ ] Thumbnail prepared (see below).
- [ ] Author access requested and approved in the portal.

## Listing Fields

| Field | Value |
| --- | --- |
| Name | Demo Mode |
| Slug | `russelcruz28-demo-mode` |
| Description | Persistent SQLite demo sandboxes with model selection, role switching, and restore without expiry for Filament panels. |
| Categories | Panel Builder, Developer Tool, Panel Authorization, Spatie |
| GitHub repository | `Russelcruz28/filament-demo-mode` |
| Documentation URL | https://raw.githubusercontent.com/Russelcruz28/filament-demo-mode/main/README.md |
| Filament versions | 4, 5 |
| Dark theme | No (the standalone role picker uses a light theme) |
| Translations | No (interface strings are English only) |
| Price | Free (MIT) |

Requirements to mention where the portal allows: PHP 8.2+, PDO SQLite, Laravel 12,
host migrations compatible with SQLite.

## Thumbnail

- 2560 × 1440 px (16:9), JPG.
- Capture from a synthetic demo app only; never show production data.
- Suggested shot: the **Demo Mode** resource with the model selection visible and
  the demo banner active, or the role picker beside it.

## Description

Demo Mode lets authorized presenters demonstrate a Filament application using a
private SQLite copy instead of editing live business records. Choose the models
whose records form the starting dataset, switch roles in a dedicated demo picker,
and restore the same sandbox later, including after logout.

The plugin retains one current sandbox per installation with no expiry. It
includes automatic Spatie Laravel Permission integration, callbacks for custom
role systems, and adapter contracts for office or tenant policies. Install and
doctor commands help configure the package. Laravel database, filesystem, cache
and supported outbound integrations are redirected or suppressed during demos.

Host migrations must support SQLite. Custom role policies, external SDKs, raw
PDO and native filesystem access require integration review. Demo Mode provides
application-level isolation, not a hardened security boundary, and copied data
is not anonymized.

## After Approval

- Add the Filament directory link to the README.
- Keep the portal's supported versions in step with `composer.json` when a new
  Filament major is supported.
