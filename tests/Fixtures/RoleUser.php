<?php

namespace DemoMode\Tests\Fixtures;

use Spatie\Permission\Traits\HasRoles;

class RoleUser extends User
{
    use HasRoles;

    protected $table = 'users';

    protected $guard_name = 'web';
}
