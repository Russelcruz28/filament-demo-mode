# Publishing

1. The public package name is `russelcruz28/filament-demo-mode`, owned by
   [Russelcruz28](https://github.com/Russelcruz28), and licensed under MIT.
2. Create a separate public GitHub repository with the **contents** of this
   directory at its root. Do not publish the full app, credentials, `.env`,
   production storage, database dumps, `vendor`, or the local Composer lock file.
3. If renaming, update package metadata, documentation, and the host's Composer
   requirement and path repository version mapping together.
4. Run `composer install`, `composer test`, `composer lint`, and
   `composer validate --strict` in the standalone repository. Run host integration
   tests too. Review SQLite compatibility and security boundaries.
5. Update CHANGELOG.md and tag the reviewed commit `v0.1.0`. Push only after
   approval. Composer derives versions from tags; do not hardcode a version.
6. Sign in to Packagist, submit the public repository URL, and enable its GitHub
   integration for automatic updates. Verify installation in a clean app.
7. Replace the host's `dev-main` dependency with `^0.1` and remove its path
   repository when adopting the public release.
8. Submit to the Filament plugin directory through its author portal. This is
   separate from publishing the Composer package on Packagist.

No repository, tag, push, or listing is created automatically by this project.

## References

- [Packagist submission and updates](https://packagist.org/about)
- [Filament panel plugins](https://filamentphp.com/docs/4.x/plugins/panel-plugins)
- [Filament author portal](https://filamentphp.com/author)
- [Composer path repositories](https://getcomposer.org/doc/05-repositories.md#path)
