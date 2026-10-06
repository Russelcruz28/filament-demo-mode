# Filament Plugin Listing

Plugins are submitted through the [Filament author portal](https://filamentphp.com/author)
(sign in with GitHub). The old pull-request flow on `filamentphp/filamentphp.com`
is archived; see the [legacy-site notice](https://github.com/filamentphp/legacy-site).

## Before Submitting

- [x] Public repository: https://github.com/Russelcruz28/filament-demo-mode
- [x] Packagist package: https://packagist.org/packages/russelcruz28/filament-demo-mode
- [x] Packagist lists the latest tag (`v0.1.1`, Filament 5 support). If it does not,
      click **Update** on the Packagist package page and enable the GitHub hook
      so future tags sync automatically.
- [ ] Clean install verified in a fresh Laravel 12 app on Filament 4 and on Filament 5.
- [x] Thumbnail prepared: `art/thumbnail.jpg` (see below).
- [ ] Author access requested and approved in the portal.

## Listing Fields

| Field | Value |
| --- | --- |
| Name | Demo Mode |
| Slug | `russel-cruz-demo-mode` (portal prefixes `russel-cruz-`; enter `demo-mode`) |
| Description | Present your Filament panel on a persistent SQLite sandbox with model selection, role switching, and restore, without touching production data. |
| Categories | Panels, Developer Tool, Panel Authorization, Spatie Integration |
| GitHub repository | `Russelcruz28/filament-demo-mode` |
| Composer package | `russelcruz28/filament-demo-mode` |
| Documentation URL | https://raw.githubusercontent.com/Russelcruz28/filament-demo-mode/main/README.md |
| Filament versions | 4, 5 |
| Dark theme | No (the standalone role picker uses a light theme) |
| Translations | No (interface strings are English only) |
| Price | Free (leave Pricing empty) |

Requirements to mention where the portal allows: PHP 8.2+, PDO SQLite, Laravel 12,
host migrations compatible with SQLite.

## Thumbnail

Upload `art/thumbnail.jpg` (2560 × 1440 px, 16:9, JPG). It is rendered from
`art/thumbnail.html` using synthetic data only. To regenerate after edits:

```sh
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new \
  --hide-scrollbars --window-size=1280,720 --force-device-scale-factor=2 \
  --screenshot=/tmp/thumbnail.png "file://$PWD/art/thumbnail.html"
sips -s format jpeg -s formatOptions 92 /tmp/thumbnail.png --out art/thumbnail.jpg
```

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
