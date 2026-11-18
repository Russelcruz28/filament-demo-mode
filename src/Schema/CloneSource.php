<?php

namespace DemoMode\Schema;

use DemoMode\Contracts\ClonesSchema;
use DemoMode\Contracts\SandboxDriver;
use DemoMode\Contracts\SchemaSource;
use DemoMode\ProvisioningFailed;
use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\SandboxDrivers;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Reads the structure of the live source and recreates it in the sandbox. Drivers that can read
 * the catalog directly implement ClonesSchema; others fall back to a dump-and-load through
 * Laravel's schema state (e.g. pgsql-database across databases).
 */
class CloneSource implements SchemaSource
{
    public function key(): string
    {
        return 'clone';
    }

    public function supports(SandboxDriver $driver, Connection $source): Capability
    {
        if ($driver instanceof ClonesSchema) {
            return $driver->canClone($source);
        }
        $family = SandboxDrivers::family($driver);
        if ($family === null || $family !== SandboxDrivers::sourceFamily($source)) {
            return Capability::unsupported('The '.$driver->key().' driver cannot clone a '.$source->getDriverName().' schema.');
        }
        foreach ($family === 'pgsql' ? ['pg_dump', 'psql'] : ['mysqldump', 'mysql'] as $binary) {
            if ((new ExecutableFinder)->find($binary) === null) {
                return Capability::unsupported("Cloning into {$driver->key()} goes through a schema dump and needs the {$binary} command on this server.");
            }
        }

        return Capability::ok();
    }

    public function build(SandboxRef $ref, SandboxDriver $driver, Connection $source, TablePlan $plan): void
    {
        $demo = DB::connection('demo');
        if ($driver instanceof ClonesSchema) {
            $driver->cloneSchema($ref, $source, $demo, $plan);

            return;
        }
        if (! $this->supports($driver, $source)->supported()) {
            throw new ProvisioningFailed(implode(' ', $this->supports($driver, $source)->reasons));
        }
        $base = tempnam(sys_get_temp_dir(), 'demo-schema-');
        $path = $base.'.sql';
        try {
            $source->getSchemaState()->dump($source, $path);
            $demo->getSchemaState()->load($path);
        } finally {
            @unlink($path);
            @unlink($base);
        }
    }
}
