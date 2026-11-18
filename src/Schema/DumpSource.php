<?php

namespace DemoMode\Schema;

use DemoMode\Contracts\SandboxDriver;
use DemoMode\Drivers\PostgresSchemaDriver;
use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\SandboxDrivers;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use Illuminate\Database\Connection;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Loads a Laravel schema dump (php artisan schema:dump) taken from the real database, then runs any
 * newer migrations. Laravel loads the file with the engine's CLI client.
 */
class DumpSource extends MigrationsSource
{
    public function key(): string
    {
        return 'dump';
    }

    public function path(Connection $source): string
    {
        return config('demo-mode.sandbox.schema_path') ?: database_path('schema/'.$source->getName().'-schema.sql');
    }

    public function supports(SandboxDriver $driver, Connection $source): Capability
    {
        if ($driver instanceof PostgresSchemaDriver) {
            return Capability::unsupported('The dump schema source cannot target pgsql-schema: pg_dump output is schema-qualified and would write to production. Use "clone" or "migrations".');
        }
        $family = SandboxDrivers::family($driver);
        if ($family !== null && $family !== SandboxDrivers::sourceFamily($source)) {
            return Capability::unsupported("A {$source->getDriverName()} schema dump cannot be loaded into a {$family} sandbox.");
        }
        $path = $this->path($source);
        if (! is_file($path)) {
            return Capability::unsupported("Schema dump not found at {$path}. Run: php artisan schema:dump --database={$source->getName()}");
        }
        $binary = match ($family) {
            'sqlite' => 'sqlite3',
            'pgsql' => str_ends_with($path, '.sql') ? 'psql' : 'pg_restore',
            'mysql' => 'mysql',
            default => null,
        };
        if ($binary !== null && (new ExecutableFinder)->find($binary) === null) {
            return Capability::unsupported("The dump schema source needs the {$binary} command on this server.");
        }

        return Capability::ok();
    }

    public function build(SandboxRef $ref, SandboxDriver $driver, Connection $source, TablePlan $plan): void
    {
        $this->migrate($ref, ['--schema-path' => $this->path($source)]);
    }
}
