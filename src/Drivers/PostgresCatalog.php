<?php

namespace DemoMode\Drivers;

use DemoMode\ProvisioningFailed;
use DemoMode\Sandbox\TablePlan;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Recreates source objects in a sandbox schema from the system catalog.
 *
 * Definitions are rendered by the source session, where source-schema objects are visible and therefore
 * unqualified, and executed by the sandbox session, whose search_path puts the sandbox schema first.
 * Only reading pg_catalog is needed, so a dedicated sandbox role without table privileges can clone.
 */
class PostgresCatalog
{
    public function __construct(
        private Connection $source,
        private string $from,
        private Connection $demo,
        private string $into,
    ) {}

    /** @return list<string> Source base tables (partitions are copied through their parent). */
    public function tables(): array
    {
        return array_column($this->source->select(
            "select c.relname as name from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = ? and c.relkind in ('r', 'p') and not c.relispartition
             and not exists (select 1 from pg_depend d where d.objid = c.oid and d.deptype = 'e')
             order by c.relname", [$this->from]), 'name');
    }

    public function cloneAll(TablePlan $plan): void
    {
        $this->cloneSequences();
        $this->cloneFunctions();
        foreach ($this->tables() as $table) {
            $this->createTable($table);
        }
        $this->cloneViews();
        $this->cloneTriggers();
        // Foreign keys are added after the copy, when they also validate the copied rows.
        $plan->meta['foreign_keys'] = $this->foreignKeys();
    }

    public function cloneSequences(): void
    {
        foreach ($this->source->select(
            "select c.relname as name from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = ? and c.relkind = 'S'
             and not exists (select 1 from pg_depend d where d.objid = c.oid and d.deptype in ('i', 'e'))", [$this->from]) as $sequence) {
            $this->createSequence($sequence->name);
        }
    }

    public function createSequence(string $name): void
    {
        $sequence = $this->source->selectOne(
            'select format_type(seqtypid, null) as type, seqstart, seqincrement, seqmin, seqmax, seqcache, seqcycle
             from pg_sequence where seqrelid = ?::regclass', [$this->qualified($this->from, $name)]);
        if ($sequence === null) {
            return;
        }
        $this->demo->statement(sprintf('create sequence if not exists %s as %s increment by %s minvalue %s maxvalue %s start with %s cache %s %s',
            $this->qualified($this->into, $name), $sequence->type, $sequence->seqincrement, $sequence->seqmin, $sequence->seqmax,
            $sequence->seqstart, $sequence->seqcache, $sequence->seqcycle ? 'cycle' : 'no cycle'));
    }

    /**
     * CREATE TABLE from the catalog: columns, defaults, identity, generated columns, collations,
     * primary/unique/check/exclusion constraints and indexes. Foreign keys are handled separately.
     */
    public function createTable(string $table): void
    {
        $relation = $this->qualified($this->from, $table);
        $columns = $this->source->select(
            'select a.attname as name, format_type(a.atttypid, a.atttypmod) as type, a.attnotnull as not_null,
                pg_get_expr(d.adbin, d.adrelid) as expression, a.attidentity as identity, a.attgenerated as generated,
                case when a.attcollation <> t.typcollation then (select quote_ident(collname) from pg_collation where oid = a.attcollation) end as collation
             from pg_attribute a join pg_type t on t.oid = a.atttypid
             left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
             where a.attrelid = ?::regclass and a.attnum > 0 and not a.attisdropped order by a.attnum', [$relation]);
        $definitions = [];
        foreach ($columns as $column) {
            // Sequences behind serial defaults must exist in the sandbox schema before the default is set.
            if ($column->expression !== null && preg_match("/nextval\('((?:[^']|'')+)'::regclass\)/", $column->expression, $match)
                && ! str_contains(str_replace('""', '', $match[1]), '.')) {
                $this->createSequence(trim(str_replace("''", "'", $match[1]), '"'));
            }
            $definitions[] = trim(implode(' ', array_filter([
                $this->demo->getQueryGrammar()->wrap($column->name), $column->type,
                $column->collation ? 'collate '.$column->collation : null,
                match (true) {
                    $column->generated === 's' => 'generated always as ('.$column->expression.') stored',
                    $column->generated === 'v' => 'generated always as ('.$column->expression.')',
                    $column->identity === 'a' => 'generated always as identity',
                    $column->identity === 'd' => 'generated by default as identity',
                    $column->expression !== null => 'default '.$column->expression,
                    default => null,
                },
                $column->not_null ? 'not null' : null,
            ])));
        }
        foreach ($this->source->select(
            "select conname as name, pg_get_constraintdef(oid) as definition from pg_constraint
             where conrelid = ?::regclass and contype in ('p', 'u', 'c', 'x') order by contype, conname", [$relation]) as $constraint) {
            $definitions[] = 'constraint '.$this->demo->getQueryGrammar()->wrap($constraint->name).' '.$constraint->definition;
        }
        $target = $this->qualified($this->into, $table);
        $this->demo->statement('create table '.$target.' ('.implode(', ', $definitions).')');
        foreach ($this->source->select(
            "select pg_get_indexdef(i.indexrelid) as definition from pg_index i where i.indrelid = ?::regclass
             and not exists (select 1 from pg_constraint c where c.conindid = i.indexrelid and c.conrelid = i.indrelid and c.contype in ('p', 'u', 'x'))", [$relation]) as $index) {
            $this->demo->statement($this->retarget($index->definition, $table));
        }
        // Re-own serial sequences so they follow the sandbox table.
        foreach ($this->source->select(
            "select s.relname as sequence, a.attname as column from pg_depend d
             join pg_class s on s.oid = d.objid and s.relkind = 'S'
             join pg_attribute a on a.attrelid = d.refobjid and a.attnum = d.refobjsubid
             where d.refobjid = ?::regclass and d.deptype = 'a'", [$relation]) as $owned) {
            $this->demo->statement('alter sequence '.$this->qualified($this->into, $owned->sequence)
                .' owned by '.$target.'.'.$this->demo->getQueryGrammar()->wrap($owned->column));
        }
    }

    /**
     * Functions and procedures are re-created in the sandbox schema. Bodies that name the source schema
     * or pin their own search_path would run against production, so they are refused.
     */
    public function cloneFunctions(): void
    {
        $functions = $this->source->select(
            "select p.proname as name, pg_get_functiondef(p.oid) as definition, p.prosrc as body,
                coalesce(array_to_string(p.proconfig, ','), '') as config
             from pg_proc p join pg_namespace n on n.oid = p.pronamespace
             where n.nspname = ? and p.prokind in ('f', 'p')
             and not exists (select 1 from pg_depend d where d.classid = 'pg_proc'::regclass and d.objid = p.oid and d.deptype = 'e')
             order by p.oid", [$this->from]);
        $unsafe = array_filter($functions, fn ($function): bool => $this->referencesSource($function->body)
            || str_contains(strtolower($function->config), 'search_path'));
        if ($unsafe !== []) {
            throw new ProvisioningFailed(sprintf('These database functions name the "%s" schema or set their own search_path, so a demo copy would run against production: %s. Use unqualified table names in function bodies.',
                $this->from, implode(', ', array_unique(array_column($unsafe, 'name')))));
        }
        $this->demo->transaction(function () use ($functions): void {
            // Bodies may reference tables that are created later.
            $this->demo->statement('set local check_function_bodies = off');
            foreach ($functions as $function) {
                $this->demo->unprepared(preg_replace('/^(CREATE OR REPLACE (?:FUNCTION|PROCEDURE) )'.preg_quote($this->quoteIdent($this->from), '/').'\./',
                    '$1'.$this->quoteIdent($this->into).'.', $function->definition, 1));
            }
        });
    }

    public function cloneViews(): void
    {
        $pending = $this->source->select(
            "select c.relname as name, c.relkind as kind, pg_get_viewdef(c.oid) as definition
             from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = ? and c.relkind in ('v', 'm')
             and not exists (select 1 from pg_depend d where d.objid = c.oid and d.deptype = 'e')", [$this->from]);
        // Views may depend on each other; retry until no further view can be created.
        while ($pending !== []) {
            $failed = [];
            $error = null;
            foreach ($pending as $view) {
                $definition = rtrim(trim($view->definition), ';');
                try {
                    $this->demo->transaction(fn () => $this->demo->statement($view->kind === 'm'
                        ? 'create materialized view '.$this->qualified($this->into, $view->name).' as '.$definition.' with no data'
                        : 'create view '.$this->qualified($this->into, $view->name).' as '.$definition));
                } catch (QueryException $exception) {
                    [$failed[], $error] = [$view, $exception];
                }
            }
            if (count($failed) === count($pending)) {
                throw $error;
            }
            $pending = $failed;
        }
    }

    public function cloneTriggers(): void
    {
        foreach ($this->source->select(
            'select c.relname as "table", pg_get_triggerdef(t.oid) as definition from pg_trigger t
             join pg_class c on c.oid = t.tgrelid join pg_namespace n on n.oid = c.relnamespace
             where not t.tgisinternal and n.nspname = ?', [$this->from]) as $trigger) {
            $this->demo->statement($this->retarget($trigger->definition, $trigger->table));
        }
    }

    /** @return list<array{table:string, name:string, definition:string}> */
    public function foreignKeys(): array
    {
        return array_map(fn ($row): array => (array) $row, $this->source->select(
            "select t.relname as \"table\", c.conname as name, pg_get_constraintdef(c.oid) as definition
             from pg_constraint c join pg_class t on t.oid = c.conrelid join pg_namespace n on n.oid = t.relnamespace
             where c.contype = 'f' and n.nspname = ? order by t.relname, c.conname", [$this->from]));
    }

    public function referencesSource(string $sql): bool
    {
        return (bool) preg_match('/(?<![\w$"])"?'.preg_quote($this->from, '/').'"?\s*\./i', $sql);
    }

    /** Point "... ON <source>.<table> ..." (index and trigger definitions) at the sandbox table. */
    private function retarget(string $definition, string $table): string
    {
        return preg_replace('/ ON (ONLY )?'.preg_quote($this->quoteIdent($this->from).'.'.$this->quoteIdent($table), '/').' /',
            ' ON $1'.$this->qualified($this->into, $table).' ', $definition, 1);
    }

    private function qualified(string $schema, string $name): string
    {
        return $this->quoteIdent($schema).'.'.$this->quoteIdent($name);
    }

    /** @var array<string, string> */
    private array $quoted = [];

    /** PostgreSQL's own quote_ident(), so names match catalog output exactly. */
    private function quoteIdent(string $name): string
    {
        return $this->quoted[$name] ??= $this->source->selectOne('select quote_ident(?) as name', [$name])->name;
    }
}
