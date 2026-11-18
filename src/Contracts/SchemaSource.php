<?php

namespace DemoMode\Contracts;

use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use Illuminate\Database\Connection;

/**
 * Builds the structure of an empty sandbox container.
 */
interface SchemaSource
{
    public function key(): string;

    public function supports(SandboxDriver $driver, Connection $source): Capability;

    public function build(SandboxRef $ref, SandboxDriver $driver, Connection $source, TablePlan $plan): void;
}
