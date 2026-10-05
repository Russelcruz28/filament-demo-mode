# Contributing

Run `composer install`, `composer test`, `composer lint`, and
`composer validate --strict` in this directory. Use `composer format` to apply
the Laravel formatting preset.

Keep application-specific models and policies in host adapters. Tests must use
private temporary storage, never a production demo root. Changes to data copying,
roles, persistence or service redirection require regression coverage. Do not
broaden supported major versions without a passing compatibility matrix.
