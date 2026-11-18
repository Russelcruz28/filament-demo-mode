<?php

namespace DemoMode\Contracts;

use Closure;

/**
 * Serialises sandbox creation, replacement and removal across requests and servers.
 */
interface LockProvider
{
    public function run(Closure $callback): mixed;
}
