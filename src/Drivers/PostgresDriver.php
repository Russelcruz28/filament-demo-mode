<?php

namespace DemoMode\Drivers;

use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Shared PostgreSQL behaviour: foreign keys are dropped before the copy and re-added afterwards
 * (re-adding validates every copied row), user triggers are disabled during the copy, and
 * sequences are moved past the copied keys.
 */
abstract class PostgresDriver extends Driver
{
    /** The schema inside the sandbox connection that holds the sandbox tables. */
    abstract protected function sandboxSchema(SandboxRef $ref, Connection $demo): string;

    public function supports(Connection $source): Capability
    {
        return $source->getDriverName() === 'pgsql' ? Capability::ok()
            : Capability::unsupported($this->key().' needs a PostgreSQL source; the source uses '.$source->getDriverName().'.');
    }

    public function beforeCopy(SandboxRef $ref, Connection $demo, TablePlan $plan): void
    {
        $schema = $this->sandboxSchema($ref, $demo);
        $catalog = new PostgresCatalog($demo, $schema, $demo, $schema);
        // Recorded before dropping so a retried step never loses a definition.
        $plan->meta['foreign_keys'] ??= $catalog->foreignKeys();
        $tables = $catalog->tables();
        foreach ($plan->meta['foreign_keys'] as $key) {
            $demo->statement('alter table '.$this->table($demo, $schema, $key['table']).' drop constraint if exists '.$this->wrap($demo, $key['name']));
        }
        foreach ($tables as $table) {
            $demo->statement('alter table '.$this->table($demo, $schema, $table).' disable trigger user');
        }
        $truncate = array_values(array_filter($tables, fn (string $table): bool => $table !== 'migrations'));
        if ($truncate !== []) {
            $demo->statement('truncate table '.implode(', ', array_map(fn ($table) => $this->table($demo, $schema, $table), $truncate)).' restart identity');
        }
        $this->grantCopyAccess($ref, $demo, $schema);
    }

    public function afterCopy(SandboxRef $ref, Connection $demo, TablePlan $plan): void
    {
        $schema = $this->sandboxSchema($ref, $demo);
        $this->resetSequences($ref, $demo, $schema);
        $violations = [];
        foreach ($plan->meta['foreign_keys'] ?? [] as $key) {
            if ($this->constraintExists($demo, $schema, $key['table'], $key['name'])) {
                continue;
            }
            try {
                $demo->statement('alter table '.$this->table($demo, $schema, $key['table']).' add constraint '.$this->wrap($demo, $key['name']).' '.$key['definition']);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? $exception->getCode()) !== '23503') {
                    throw $exception;
                }
                $violations[] = $key['table'].': '.$key['definition'];
            }
        }
        $plan->meta['violations'] = $violations;
        foreach ((new PostgresCatalog($demo, $schema, $demo, $schema))->tables() as $table) {
            $demo->statement('alter table '.$this->table($demo, $schema, $table).' enable trigger user');
        }
        foreach ($demo->select('select matviewname as name from pg_matviews where schemaname = ?', [$schema]) as $view) {
            $demo->statement('refresh materialized view '.$this->table($demo, $schema, $view->name));
        }
        $this->revokeCopyAccess($ref, $demo, $schema);
    }

    public function verify(SandboxRef $ref, Connection $demo, TablePlan $plan): array
    {
        return $plan->meta['violations'] ?? [];
    }

    /**
     * Owned sequences (serial and identity) continue after the copied maximum; standalone
     * sequences used by defaults (number series) take the source's current value.
     */
    protected function resetSequences(SandboxRef $ref, Connection $demo, string $schema): void
    {
        foreach ($demo->select(
            "select s.relname as sequence, t.relname as \"table\", a.attname as column from pg_class s
             join pg_namespace n on n.oid = s.relnamespace
             join pg_depend d on d.objid = s.oid and d.classid = 'pg_class'::regclass and d.refclassid = 'pg_class'::regclass and d.deptype in ('a', 'i')
             join pg_class t on t.oid = d.refobjid join pg_attribute a on a.attrelid = t.oid and a.attnum = d.refobjsubid
             where s.relkind = 'S' and n.nspname = ?", [$schema]) as $owned) {
            $max = $demo->table($schema.'.'.$owned->table)->max($owned->column);
            if ($max !== null) {
                $demo->select('select setval(?::regclass, ?, true)', [$this->table($demo, $schema, $owned->sequence), $max]);
            }
        }
        $source = DB::connection($ref->source);
        $sourceSchema = $source->selectOne('select current_schema() as name')->name;
        foreach ($demo->select(
            "select s.relname as name from pg_class s join pg_namespace n on n.oid = s.relnamespace
             where s.relkind = 'S' and n.nspname = ?
             and not exists (select 1 from pg_depend d where d.objid = s.oid and d.deptype in ('a', 'i'))", [$schema]) as $sequence) {
            $exists = $source->selectOne("select 1 as found from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relkind = 'S' and n.nspname = ? and c.relname = ?", [$sourceSchema, $sequence->name]);
            if ($exists !== null) {
                $state = $source->selectOne('select last_value, is_called from '.$this->table($source, $sourceSchema, $sequence->name));
                $demo->select('select setval(?::regclass, ?, ?)', [$this->table($demo, $schema, $sequence->name), $state->last_value, $state->is_called]);
            }
        }
    }

    /**
     * Set-based copies run on the source connection. When the sandbox is owned by a dedicated role,
     * that role lets the source role insert into its tables for the duration of the copy.
     */
    protected function grantCopyAccess(SandboxRef $ref, Connection $demo, string $schema): void
    {
        if (($role = $this->copyRole($ref, $demo)) !== null) {
            $demo->statement('grant usage on schema '.$this->wrap($demo, $schema).' to '.$this->wrap($demo, $role));
            $demo->statement('grant insert on all tables in schema '.$this->wrap($demo, $schema).' to '.$this->wrap($demo, $role));
        }
    }

    protected function revokeCopyAccess(SandboxRef $ref, Connection $demo, string $schema): void
    {
        if (($role = $this->copyRole($ref, $demo)) !== null) {
            $demo->statement('revoke insert on all tables in schema '.$this->wrap($demo, $schema).' from '.$this->wrap($demo, $role));
            $demo->statement('revoke usage on schema '.$this->wrap($demo, $schema).' from '.$this->wrap($demo, $role));
        }
    }

    protected function copyRole(SandboxRef $ref, Connection $demo): ?string
    {
        return null;
    }

    protected function constraintExists(Connection $demo, string $schema, string $table, string $name): bool
    {
        return $demo->selectOne('select 1 as found from pg_constraint c join pg_class t on t.oid = c.conrelid
            join pg_namespace n on n.oid = t.relnamespace where n.nspname = ? and t.relname = ? and c.conname = ?', [$schema, $table, $name]) !== null;
    }

    protected function table(Connection $connection, string $schema, string $table): string
    {
        return $this->wrap($connection, $schema).'.'.$this->wrap($connection, $table);
    }

    protected function wrap(Connection $connection, string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
