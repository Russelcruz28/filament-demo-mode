<?php

use DemoMode\Adapters\DefaultApplicationAdapter;

return [
    'enabled' => env('DEMO_MODE_ENABLED', true),
    'adapter' => DefaultApplicationAdapter::class,
    'panel' => 'admin',
    'authorized_roles' => ['super_admin'],
    'role_guard' => 'web',
    'models' => [],
    'model_namespace' => 'App\\Models\\',
    'model_path' => app_path('Models'),
    'required_tables' => [],
    'excluded_tables' => ['migrations', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'password_reset_tokens', 'demo_settings', 'demo_sandboxes'],
    'root' => env('DEMO_MODE_ROOT', storage_path('app/demo-mode')),
    'session_keys' => ['demo_role', 'password_hash_'],
    'sandbox_config' => [],
    'sandbox_cache' => [],
    // Large tables are copied in chunks across short requests to avoid timeouts.
    'provisioning' => [
        'chunk_size' => 1000,
        'step_seconds' => 5,
    ],

    // Where sandboxes live and how they get their structure.
    'sandbox' => [
        // auto (same engine as the source) | sqlite | pgsql-schema | pgsql-database | mysql-database | driver class
        'driver' => env('DEMO_MODE_DRIVER', 'sqlite'),
        // migrations | dump | clone
        'schema' => env('DEMO_MODE_SCHEMA', 'migrations'),
        // dump file; defaults to database/schema/{source}-schema.sql
        'schema_path' => null,
        // auto | sql (set-based, same server) | stream (rows through PHP / COPY)
        'copy' => 'auto',
        'prefix' => 'demo_',
        // Separate connection (credentials) for sandboxes, e.g. a dedicated PostgreSQL role that owns
        // the demo_* schemas and has no privileges on production tables.
        'connection' => env('DEMO_MODE_CONNECTION'),
        // pgsql-schema: refuse to run without a dedicated role. null = only in production.
        'require_dedicated_role' => null,
        // pgsql-schema: schemas appended after the sandbox schema (extension operators, types).
        'search_path' => ['public'],
        // mysql-database: pre-created databases for hosts without CREATE privilege.
        'pool' => [],
        // fail | warn when source columns are missing from the sandbox schema.
        'on_column_mismatch' => 'fail',
    ],

    // What other database connections do while a demo is active.
    'connections' => [
        // auto: connections to the same physical database as the source resolve to the sandbox,
        // everything else is blocked. block: only the lists below apply.
        'policy' => 'auto',
        'sandbox' => [],
        'allow' => [],
    ],

    'runtime' => [
        // file (per-sandbox directory) | prefix (default store with a demo:<token>: prefix)
        'cache' => 'file',
        // fake (jobs are dropped) | sync (jobs run inline inside the sandbox)
        'queue' => 'fake',
        'pause' => ['telescope', 'pulse'],
    ],

    // Start Demo publishes the demo for demo users and leaves the starter on production.
    // true: the starter enters the new demo as a presenter (the 0.1 behaviour).
    'enter_on_start' => false,

    // Explain entering, resetting and leaving the demo in the top bar on the next page.
    'notices' => true,

    // Users tagged in the Demo Mode settings always sign in to the saved demo.
    'demo_users' => [
        'enabled' => true,
        'search_columns' => ['name', 'email'],
    ],

    // Container swaps applied while a demo is active: [Abstract::class => Replacement::class]
    'sandbox_bindings' => [],

    // file (single server) | database (demo_sandboxes table, shared by app servers)
    'metadata' => 'file',
    // file | cache | database
    'lock' => 'file',
    'lock_store' => null,
    'metadata_connection' => null,
];
