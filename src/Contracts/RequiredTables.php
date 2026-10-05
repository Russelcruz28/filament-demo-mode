<?php

namespace DemoMode\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface RequiredTables
{
    /** @return array<int, string> */
    public function requiredTables(Authenticatable $user): array;
}
