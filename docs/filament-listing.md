# Filament Plugin Listing

Use these details in the [Filament author portal](https://filamentphp.com/author)
after the public repository and Packagist release are available.

## Listing Details

- Name: Demo Mode
- Summary: Persistent SQLite demo sandboxes with model selection, role switching,
  and restore without expiry for Filament panels.
- Price: Free
- License: MIT
- Supported Filament version: 4
- Supported Laravel version: 12
- PHP: 8.2 or later with PDO SQLite
- Category: Choose the portal's applicable panel or utility category.
- GitHub repository: https://github.com/Russelcruz28/filament-demo-mode
- Package: russelcruz28/filament-demo-mode
- Documentation: https://github.com/Russelcruz28/filament-demo-mode/blob/main/README.md
- Dark theme: Do not claim full support; the standalone role picker uses a light theme.
- Translations: Do not claim multilingual support; interface strings are currently English.

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

## Submission Checklist

1. Confirm author ownership and publish the public repository and release tag.
2. Submit the repository URL to Packagist and verify a clean Composer install.
3. Sign in to the Filament author portal and request/create author access as offered.
4. Create the plugin listing with accurate compatibility and links.
5. Upload screenshots from a synthetic demo app only; do not expose production data.
6. Submit for review and address the portal's requested changes.

The author portal requires account access and may require approval. A GitHub
release does not automatically create a Filament directory listing. The current
submission process is documented in the
[official legacy-site notice](https://github.com/filamentphp/legacy-site).
