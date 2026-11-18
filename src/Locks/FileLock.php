<?php

namespace DemoMode\Locks;

use Closure;
use DemoMode\Contracts\LockProvider;
use Illuminate\Support\Facades\File;

/**
 * flock() on <root>/.sandbox.lock. Only serialises requests on one server.
 */
class FileLock implements LockProvider
{
    public function run(Closure $callback): mixed
    {
        File::ensureDirectoryExists(config('demo-mode.root'), 0700);
        $lock = fopen(config('demo-mode.root').'/.sandbox.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not open the demo storage lock.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock demo storage.');
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
