<?php

namespace DemoMode;

use Closure;

class DemoModeOptions
{
    public ?Closure $authorize = null;

    public ?Closure $prepare = null;

    public ?Closure $synchronizeRole = null;

    public ?Closure $roles = null;

    public ?Closure $switchRole = null;

    public ?Closure $exit = null;
}
