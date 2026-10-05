# Security

Demo Mode is an application-level integration, not a hardened execution sandbox.
Only trusted administrators should start demos. Verify all integrations in a
non-production environment before enabling production access.

The package guards Laravel-managed database connections and redirects supported
Laravel services. It cannot intercept raw PDO, external SDK clients, native
filesystem access, or clients retained outside Laravel. Review these paths and
provide sandbox replacements before allowing the corresponding workflows.

Copied data retains production values, including authentication records and
potential personal data. Model selection does not anonymize records. Dependency
tables may be copied even when not explicitly selected. Restrict storage access,
exclude demo directories from public serving, and protect backups. Delete saved
data when its retention is no longer appropriate.

Role switching grants broad permissions to the sandbox presenter. Keep entry
authorization fail-closed and enforce tenant/office constraints in adapters.
Do not share a sandbox with untrusted users. Reset replaces the single current
installation-wide copy and invalidates older sessions.

Non-web routes and long-lived workers require separate verification. No Octane
compatibility or protection against arbitrary PHP execution is claimed.

Report suspected vulnerabilities privately to the repository maintainer through
GitHub private vulnerability reporting when enabled. Do not post production
credentials or copied personal data in public issues.
