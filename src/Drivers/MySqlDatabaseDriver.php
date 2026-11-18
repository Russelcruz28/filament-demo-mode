<?php

namespace DemoMode\Drivers;

use DemoMode\Contracts\ClonesSchema;
use DemoMode\Contracts\MetadataStore;
use DemoMode\ProvisioningFailed;
use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\ChunkResult;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use DemoMode\Sandbox\TableState;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One database per sandbox on the source server (CREATE DATABASE demo_<hash>), or a configured pool of
 * pre-created databases for hosts without CREATE privilege.
 *
 * MySQL DDL is not transactional, so every step checks state before acting. Triggers cannot be
 * disabled: they are dropped before the copy and recreated afterwards.
 */
class MySqlDatabaseDriver extends Driver implements ClonesSchema
{
    public function key(): string
    {
        return 'mysql-database';
    }

    public function supports(Connection $source): Capability
    {
        if (! in_array($source->getDriverName(), ['mysql', 'mariadb'], true)) {
            return Capability::unsupported('mysql-database needs a MySQL or MariaDB source; the source uses '.$source->getDriverName().'.');
        }
        $admin = $this->admin();
        if (($pool = $this->pool()) !== []) {
            $missing = array_diff($pool, array_column($admin->select('select schema_name as name from information_schema.schemata'), 'name'));

            return $missing === [] ? Capability::ok() : Capability::unsupported('Sandbox pool databases do not exist: '.implode(', ', $missing).'.');
        }
        foreach ($admin->select('show grants') as $grant) {
            $grant = (string) array_values((array) $grant)[0];
            if (preg_match('/^GRANT (.+) ON (\*\.\*|`'.preg_quote(str_replace('_', '\_', $this->prefix()), '/').'%`\.\*) TO/i', $grant, $match)
                && preg_match('/\b(ALL PRIVILEGES|CREATE)\b/i', $match[1])) {
                return Capability::ok();
            }
        }

        return Capability::warning('Could not confirm the CREATE privilege for sandbox databases. Grant it, or configure demo-mode.sandbox.pool.');
    }

    public function ref(string $token, ?string $owner, Connection $source): SandboxRef
    {
        if (($pool = $this->pool()) === []) {
            return parent::ref($token, $owner, $source);
        }
        // Use a pool database other than the saved demo's, so Reset keeps it until the new one is ready.
        $current = app(MetadataStore::class)->current()['ref']['container'] ?? null;
        $free = array_values(array_diff($pool, [$current]));

        return new SandboxRef($token, $this->key(), $free[0] ?? $pool[0], $owner, $source->getName());
    }

    public function create(SandboxRef $ref, Connection $source): void
    {
        $admin = $this->admin($ref);
        if (in_array($ref->container, $this->pool(), true)) {
            $this->clear($ref);

            return;
        }
        $charset = $source->getConfig('charset') ?: 'utf8mb4';
        $collation = $source->getConfig('collation');
        $admin->statement('create database if not exists '.$this->wrap($admin, $ref->container)
            .' character set '.$charset.($collation ? ' collate '.$collation : ''));
    }

    public function exists(SandboxRef $ref): bool
    {
        return $this->admin($ref)->selectOne('select schema_name from information_schema.schemata where schema_name = ?', [$ref->container]) !== null;
    }

    public function connectionConfig(SandboxRef $ref, array $sourceConfig): array
    {
        return [...$this->baseConfig($sourceConfig), 'database' => $ref->container];
    }

    public function beforeCopy(SandboxRef $ref, Connection $demo, TablePlan $plan): void
    {
        $demo->statement('set foreign_key_checks = 0');
        $tables = $this->tables($demo);
        $plan->meta['foreign_keys'] ??= $this->foreignKeys($demo, $tables);
        $plan->meta['triggers'] ??= $this->triggers($demo, $demo->getDatabaseName());
        foreach ($plan->meta['foreign_keys'] as $key) {
            if ($this->constraintExists($demo, $key['table'], $key['name'])) {
                $demo->statement('alter table '.$this->wrap($demo, $key['table']).' drop foreign key '.$this->wrap($demo, $key['name']));
            }
        }
        foreach ($plan->meta['triggers'] as $trigger) {
            $demo->statement('drop trigger if exists '.$this->wrap($demo, $trigger['name']));
        }
        $keep = ($plan->meta['data_cloned'] ?? false) ? $plan->names() : [];
        foreach ($tables as $table) {
            if ($table !== 'migrations' && ! in_array($table, $keep, true)) {
                $demo->statement('truncate table '.$this->wrap($demo, $table));
            }
        }
    }

    public function copyChunk(SandboxRef $ref, Connection $source, Connection $demo, TableState $table, int $size): ChunkResult
    {
        if ($this->setBased($source, $demo)) {
            return $this->copier->sql($source, $source->getDatabaseName().'.'.$table->name, $ref->container.'.'.$table->name, $table, $this->size($size));
        }

        return $demo->transaction(function () use ($source, $demo, $table, $size): ChunkResult {
            $demo->statement('set foreign_key_checks = 0');

            return $this->copier->stream($source, $demo, $table, $this->size($size));
        });
    }

    public function afterCopy(SandboxRef $ref, Connection $demo, TablePlan $plan): void
    {
        foreach ($demo->select("select table_name as `table`, column_name as `column` from information_schema.columns
            where table_schema = ? and extra like '%auto_increment%'", [$demo->getDatabaseName()]) as $column) {
            $max = $demo->table($column->table)->max($column->column);
            if ($max !== null) {
                $demo->statement('alter table '.$this->wrap($demo, $column->table).' auto_increment = '.((int) $max + 1));
            }
        }
        $demo->statement('set foreign_key_checks = 1');
        $violations = [];
        foreach ($plan->meta['foreign_keys'] ?? [] as $key) {
            if ($this->constraintExists($demo, $key['table'], $key['name'])) {
                continue;
            }
            try {
                $demo->statement('alter table '.$this->wrap($demo, $key['table']).' add '.$key['definition']);
            } catch (QueryException $exception) {
                if (! in_array($exception->errorInfo[1] ?? null, [1452, 1216], true)) {
                    throw $exception;
                }
                $violations[] = $key['table'].': '.$key['definition'];
            }
        }
        $plan->meta['violations'] = $violations;
        $existing = array_column($this->triggers($demo, $demo->getDatabaseName()), 'name');
        foreach ($plan->meta['triggers'] ?? [] as $trigger) {
            if (! in_array($trigger['name'], $existing, true)) {
                $demo->unprepared($trigger['definition']);
            }
        }
    }

    public function verify(SandboxRef $ref, Connection $demo, TablePlan $plan): array
    {
        return $plan->meta['violations'] ?? [];
    }

    public function drop(SandboxRef $ref): void
    {
        DB::purge('demo');
        if (in_array($ref->container, $this->pool(), true)) {
            $this->clear($ref);

            return;
        }
        $admin = $this->admin($ref);
        $admin->statement('drop database if exists '.$this->wrap($admin, $ref->container));
    }

    public function list(): array
    {
        if (($pool = $this->pool()) !== []) {
            return $pool;
        }

        return array_values(array_filter(array_column($this->admin()->select('select schema_name as name from information_schema.schemata'), 'name'), $this->owns(...)));
    }

    public function canClone(Connection $source): Capability
    {
        return Capability::ok();
    }

    /**
     * Recreate tables, views, routines and triggers from SHOW CREATE output, with DEFINER removed.
     * Foreign keys come with the tables and are dropped and re-validated around the copy.
     */
    public function cloneSchema(SandboxRef $ref, Connection $source, Connection $demo, TablePlan $plan): void
    {
        $database = $source->getDatabaseName();
        $demo->statement('set foreign_key_checks = 0');
        $objects = $source->select('select table_name as name, table_type as type from information_schema.tables where table_schema = ? order by table_name', [$database]);
        foreach ($objects as $object) {
            if ($object->type === 'BASE TABLE') {
                $create = (array) $source->selectOne('show create table '.$this->qualified($source, $database, $object->name));
                $demo->unprepared(preg_replace('/ AUTO_INCREMENT=\d+/', '', $create['Create Table']));
            }
        }
        $routines = $source->select('select routine_name as name, routine_type as type, routine_definition as body from information_schema.routines where routine_schema = ?', [$database]);
        $unsafe = array_filter($routines, fn ($routine): bool => $this->referencesDatabase($routine->body ?? '', $database));
        if ($unsafe !== []) {
            throw new ProvisioningFailed(sprintf('These routines name the "%s" database, so a demo copy would run against production: %s. Use unqualified table names in routine bodies.',
                $database, implode(', ', array_column($unsafe, 'name'))));
        }
        foreach ($routines as $routine) {
            $create = (array) $source->selectOne('show create '.strtolower($routine->type).' '.$this->qualified($source, $database, $routine->name));
            $definition = $create['Create '.ucfirst(strtolower($routine->type))] ?? null;
            if ($definition === null) {
                throw new ProvisioningFailed('The source connection cannot read the definition of routine '.$routine->name.'. Grant SHOW_ROUTINE or use the migrations schema source.');
            }
            $demo->unprepared($this->withoutDefiner($definition));
        }
        $views = array_values(array_filter($objects, fn ($object): bool => $object->type === 'VIEW'));
        while ($views !== []) {
            [$failed, $error] = [[], null];
            foreach ($views as $view) {
                $create = (array) $source->selectOne('show create view '.$this->qualified($source, $database, $view->name));
                try {
                    $demo->unprepared(str_replace($this->wrap($source, $database).'.', '', $this->withoutDefiner($create['Create View'])));
                } catch (QueryException $exception) {
                    [$failed[], $error] = [$view, $exception];
                }
            }
            if (count($failed) === count($views)) {
                throw $error;
            }
            $views = $failed;
        }
        foreach ($this->triggers($source, $database) as $trigger) {
            $demo->unprepared($trigger['definition']);
        }
    }

    public function referencesDatabase(string $sql, string $database): bool
    {
        return (bool) preg_match('/(?<![\w$])`?'.preg_quote($database, '/').'`?\s*\./i', $sql);
    }

    private function setBased(Connection $source, Connection $demo): bool
    {
        return match ($this->strategy()) {
            'sql' => true,
            'stream' => false,
            // INSERT ... SELECT needs one login that can read the source and write the sandbox on one server.
            default => $source->getConfig('host') === $demo->getConfig('host') && $source->getConfig('port') === $demo->getConfig('port')
                && $source->getConfig('username') === $demo->getConfig('username'),
        };
    }

    /** @return list<string> */
    private function tables(Connection $connection): array
    {
        return array_column($connection->select("select table_name as name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'", [$connection->getDatabaseName()]), 'name');
    }

    /** @return list<array{table:string, name:string, definition:string}> */
    private function foreignKeys(Connection $connection, array $tables): array
    {
        $keys = [];
        foreach ($tables as $table) {
            $create = ((array) $connection->selectOne('show create table '.$this->wrap($connection, $table)))['Create Table'];
            preg_match_all('/^\s*(CONSTRAINT `((?:[^`]|``)+)` FOREIGN KEY .+?),?$/m', $create, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $keys[] = ['table' => $table, 'name' => str_replace('``', '`', $match[2]), 'definition' => $match[1]];
            }
        }

        return $keys;
    }

    /** @return list<array{name:string, definition:string}> */
    private function triggers(Connection $connection, string $database): array
    {
        return array_map(function ($trigger) use ($connection, $database): array {
            $create = (array) $connection->selectOne('show create trigger '.$this->qualified($connection, $database, $trigger->name));

            return ['name' => $trigger->name, 'definition' => $this->withoutDefiner($create['SQL Original Statement'])];
        }, $connection->select('select trigger_name as name from information_schema.triggers where trigger_schema = ? order by action_order', [$database]));
    }

    private function constraintExists(Connection $connection, string $table, string $name): bool
    {
        return $connection->selectOne("select 1 as found from information_schema.table_constraints where constraint_schema = ? and table_name = ? and constraint_name = ? and constraint_type = 'FOREIGN KEY'",
            [$connection->getDatabaseName(), $table, $name]) !== null;
    }

    /** Pool databases are reused, so they are emptied instead of dropped. */
    private function clear(SandboxRef $ref): void
    {
        $this->connectionFor($ref, function (Connection $demo): void {
            $demo->statement('set foreign_key_checks = 0');
            $database = $demo->getDatabaseName();
            foreach ($demo->select('select table_name as name, table_type as type from information_schema.tables where table_schema = ?', [$database]) as $object) {
                $demo->statement(($object->type === 'VIEW' ? 'drop view if exists ' : 'drop table if exists ').$this->wrap($demo, $object->name));
            }
            foreach ($demo->select('select routine_name as name, routine_type as type from information_schema.routines where routine_schema = ?', [$database]) as $routine) {
                $demo->statement('drop '.strtolower($routine->type).' if exists '.$this->wrap($demo, $routine->name));
            }
        });
    }

    private function connectionFor(SandboxRef $ref, \Closure $callback): void
    {
        $name = 'demo_mode_pool';
        config(['database.connections.'.$name => $this->connectionConfig($ref, (array) config('database.connections.'.($ref->source ?? config('database.default'))))]);
        try {
            $callback(DB::connection($name));
        } finally {
            DB::purge($name);
        }
    }

    /** @return list<string> */
    private function pool(): array
    {
        return array_values((array) config('demo-mode.sandbox.pool', []));
    }

    private function withoutDefiner(string $sql): string
    {
        return preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql, 1);
    }

    private function qualified(Connection $connection, string $database, string $name): string
    {
        return $this->wrap($connection, $database).'.'.$this->wrap($connection, $name);
    }

    protected function wrap(Connection $connection, string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
