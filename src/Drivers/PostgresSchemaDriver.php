<?php

namespace DemoMode\Drivers;

use DemoMode\Contracts\ClonesSchema;
use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\ChunkResult;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use DemoMode\Sandbox\TableState;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * One schema per sandbox inside the source database: CREATE SCHEMA demo_<hash>.
 *
 * The sandbox connection's search_path is the sandbox schema followed by the configured extension
 * schemas. With "public" on that path, an unqualified table missing from the sandbox would resolve to
 * production, so verify() creates every missing source table, and a dedicated role without
 * privileges on production tables turns any remaining fall-through into an error.
 */
class PostgresSchemaDriver extends PostgresDriver implements ClonesSchema
{
    public function key(): string
    {
        return 'pgsql-schema';
    }

    public function supports(Connection $source): Capability
    {
        $capability = parent::supports($source);
        if (! $capability->supported()) {
            return $capability;
        }
        if (! config('demo-mode.sandbox.connection')) {
            $required = config('demo-mode.sandbox.require_dedicated_role') ?? app()->isProduction();
            $message = 'No dedicated sandbox role is configured (demo-mode.sandbox.connection). Queries for tables missing from the sandbox could fall through to production tables on the search_path.';
            $capability = $capability->merge($required ? Capability::unsupported($message) : Capability::warning($message));
        }
        $admin = $this->admin();
        if ($admin->getDriverName() !== 'pgsql' || $admin->selectOne('select current_database() as name')->name !== $source->selectOne('select current_database() as name')->name) {
            return $capability->merge(Capability::unsupported('The sandbox connection must use the same PostgreSQL database as the source.'));
        }
        if (! $admin->selectOne("select has_database_privilege(current_user, current_database(), 'CREATE') as allowed")->allowed) {
            $capability = $capability->merge(Capability::unsupported('The sandbox role needs CREATE on the database to create sandbox schemas.'));
        }

        return $capability;
    }

    public function create(SandboxRef $ref, Connection $source): void
    {
        $this->admin($ref)->statement('create schema if not exists '.$this->wrap($source, $ref->container));
    }

    public function exists(SandboxRef $ref): bool
    {
        return $this->admin($ref)->selectOne('select 1 as found from pg_namespace where nspname = ?', [$ref->container]) !== null;
    }

    public function connectionConfig(SandboxRef $ref, array $sourceConfig): array
    {
        $config = $this->baseConfig($sourceConfig);
        unset($config['schema']);

        return [...$config, 'search_path' => array_values(array_unique([$ref->container, ...(array) config('demo-mode.sandbox.search_path', ['public'])]))];
    }

    protected function sandboxSchema(SandboxRef $ref, Connection $demo): string
    {
        return $ref->container;
    }

    public function copyChunk(SandboxRef $ref, Connection $source, Connection $demo, TableState $table, int $size): ChunkResult
    {
        if ($this->strategy() === 'stream') {
            return $demo->transaction(fn (): ChunkResult => $this->copier->postgresCopy($source, $demo, $table, $this->size($size)));
        }
        $sourceSchema = $source->selectOne('select current_schema() as name')->name;

        // Rows move server-side; identity columns keep their source values.
        return $this->copier->sql($source, $sourceSchema.'.'.$table->name, $ref->container.'.'.$table->name,
            $table, $this->size($size), 'overriding system value');
    }

    public function verify(SandboxRef $ref, Connection $demo, TablePlan $plan): array
    {
        // Table parity: every source table exists in the sandbox, empty when it was not selected.
        $source = DB::connection($ref->source);
        $catalog = new PostgresCatalog($source, $source->selectOne('select current_schema() as name')->name, $demo, $ref->container);
        $present = (new PostgresCatalog($demo, $ref->container, $demo, $ref->container))->tables();
        foreach (array_diff($catalog->tables(), $present) as $table) {
            $catalog->createTable($table);
        }

        return parent::verify($ref, $demo, $plan);
    }

    protected function copyRole(SandboxRef $ref, Connection $demo): ?string
    {
        if ($this->strategy() === 'stream') {
            return null;
        }
        $sourceRole = DB::connection($ref->source)->selectOne('select current_user as name')->name;

        return $sourceRole === $demo->selectOne('select current_user as name')->name ? null : $sourceRole;
    }

    public function drop(SandboxRef $ref): void
    {
        DB::purge('demo');
        $admin = $this->admin($ref);
        $admin->statement('drop schema if exists '.$this->wrap($admin, $ref->container).' cascade');
    }

    public function list(): array
    {
        return array_values(array_filter(array_column($this->admin()->select('select nspname as name from pg_namespace'), 'name'), $this->owns(...)));
    }

    public function canClone(Connection $source): Capability
    {
        return Capability::ok();
    }

    public function cloneSchema(SandboxRef $ref, Connection $source, Connection $demo, TablePlan $plan): void
    {
        (new PostgresCatalog($source, $source->selectOne('select current_schema() as name')->name, $demo, $ref->container))->cloneAll($plan);
    }
}
