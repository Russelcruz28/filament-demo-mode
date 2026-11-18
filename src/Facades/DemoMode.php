<?php

namespace DemoMode\Facades;

use DemoMode\DemoManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool active()
 * @method static bool canManage()
 * @method static bool canRestore()
 * @method static string|null currentToken()
 *
 * @see DemoManager
 */
class DemoMode extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DemoManager::class;
    }
}
