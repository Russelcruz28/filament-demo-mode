<?php

namespace DemoMode\Locks;

use Closure;
use DemoMode\Contracts\LockProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Cache::lock() on a store shared by every app server (Redis, Memcached, database, DynamoDB).
 */
class CacheLock implements LockProvider
{
    public function run(Closure $callback): mixed
    {
        return Cache::store(config('demo-mode.lock_store'))
            ->lock('demo-mode:sandbox', 600)
            ->block(60, $callback);
    }
}
