<?php

namespace DemoMode\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ApplicationAdapter
{
    public function canManage(?Authenticatable $user): bool;

    public function prepare(Authenticatable $user): void;

    public function synchronizeRole(Authenticatable $user): void;

    public function clearContext(): void;

    public function destination(): string;
}
