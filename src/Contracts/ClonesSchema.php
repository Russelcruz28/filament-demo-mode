<?php

namespace DemoMode\Contracts;

use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use Illuminate\Database\Connection;

/**
 * A driver that can recreate the live source structure without migrations or CLI tools.
 */
interface ClonesSchema
{
    public function canClone(Connection $source): Capability;

    public function cloneSchema(SandboxRef $ref, Connection $source, Connection $demo, TablePlan $plan): void;
}
