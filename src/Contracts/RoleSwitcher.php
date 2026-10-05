<?php

namespace DemoMode\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface RoleSwitcher
{
    /** @return array<int, array{id:string, label:string, group:string, selected:bool}> */
    public function roles(Authenticatable $user): array;

    public function switchRole(Authenticatable $user, string $id): string;
}
