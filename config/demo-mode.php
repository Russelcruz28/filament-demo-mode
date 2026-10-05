<?php

use DemoMode\Adapters\DefaultApplicationAdapter;

return [
    'enabled' => true,
    'adapter' => DefaultApplicationAdapter::class,
    'panel' => 'admin',
    'authorized_roles' => ['super_admin'],
    'role_guard' => 'web',
    'models' => [],
    'model_namespace' => 'App\\Models\\',
    'model_path' => app_path('Models'),
    'required_tables' => [],
    'excluded_tables' => ['migrations', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'password_reset_tokens', 'demo_settings'],
    'root' => env('DEMO_MODE_ROOT', storage_path('app/demo-mode')),
    'session_keys' => ['demo_role', 'password_hash_'],
    'sandbox_config' => [],
    'sandbox_cache' => [],
];
