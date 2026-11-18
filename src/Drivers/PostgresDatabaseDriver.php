<?php

namespace DemoMode\Drivers;

use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\ChunkResult;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TableState;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * One database per sandbox (CREATE DATABASE demo_<hash>), optionally on another server through
 * demo-mode.sandbox.connection. Full isolation; rows are streamed with COPY.
 */
class PostgresDatabaseDriver extends PostgresDriver
{
    public function key(): string
    {
        return 'pgsql-database';
    }

    public function supports(Connection $source): Capability
    {
        $capability = parent::supports($source);
        if ($capability->supported() && ! $this->admin()->selectOne('select rolcreatedb or rolsuper as allowed from pg_roles where rolname = current_user')->allowed) {
            return $capability->merge(Capability::unsupported('The sandbox role needs CREATEDB to create sandbox databases.'));
        }

        return $capability;
    }

    public function create(SandboxRef $ref, Connection $source): void
    {
        if (! $this->exists($ref)) {
            $admin = $this->admin($ref);
            $admin->statement('create database '.$this->wrap($admin, $ref->container));
        }
    }

    public function exists(SandboxRef $ref): bool
    {
        return $this->admin($ref)->selectOne('select 1 as found from pg_database where datname = ?', [$ref->container]) !== null;
    }

    public function connectionConfig(SandboxRef $ref, array $sourceConfig): array
    {
        return [...$this->baseConfig($sourceConfig), 'database' => $ref->container,
            'search_path' => $sourceConfig['search_path'] ?? $sourceConfig['schema'] ?? 'public'];
    }

    protected function sandboxSchema(SandboxRef $ref, Connection $demo): string
    {
        return $demo->selectOne('select current_schema() as name')->name;
    }

    public function copyChunk(SandboxRef $ref, Connection $source, Connection $demo, TableState $table, int $size): ChunkResult
    {
        // No cross-database INSERT ... SELECT without dblink, so rows always stream.
        return $demo->transaction(fn (): ChunkResult => $this->copier->postgresCopy($source, $demo, $table, $this->size($size)));
    }

    public function drop(SandboxRef $ref): void
    {
        DB::purge('demo');
        $admin = $this->admin($ref);
        $force = (int) $admin->selectOne('show server_version_num')->server_version_num >= 130000 ? ' with (force)' : '';
        $admin->statement('drop database if exists '.$this->wrap($admin, $ref->container).$force);
    }

    public function list(): array
    {
        return array_values(array_filter(array_column($this->admin()->select('select datname as name from pg_database'), 'name'), $this->owns(...)));
    }
}
