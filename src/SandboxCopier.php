<?php

namespace DemoMode;

use DemoMode\Sandbox\ChunkResult;
use DemoMode\Sandbox\TablePlan;
use DemoMode\Sandbox\TableState;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Copy strategies shared by the sandbox drivers. Every strategy pages by key (keyset paging,
 * composite keys included) and is resumable from the TableState it is given.
 */
class SandboxCopier
{
    // Bound parameters per INSERT, safe for SQLite builds older than 3.32.
    private const SQLITE_MAX_BINDINGS = 999;

    private const MAX_BINDINGS = 10000;

    private const UNORDERABLE = ['json', 'xml', 'point', 'line', 'lseg', 'box', 'path', 'polygon', 'circle'];

    public function plan(Connection $source, array $tables): TablePlan
    {
        $schema = $source->getSchemaBuilder();

        return new TablePlan(array_map(function (string $name) use ($source, $schema): TableState {
            $primary = collect($schema->getIndexes($name))->firstWhere('primary', true)['columns'] ?? null;

            // Only primary keys are trusted for keyset paging: unique indexes may be partial or nullable.
            return new TableState($name, is_array($primary) && $primary !== [] ? array_values($primary) : null,
                total: $source->table($name)->count());
        }, $tables));
    }

    /**
     * Read rows through PHP and insert them in batches. Works across servers and drivers.
     */
    public function stream(Connection $source, Connection $demo, TableState $table, int $size, ?string $from = null, ?string $into = null): ChunkResult
    {
        $columns = $table->columns ?? [];
        if ($columns === []) {
            return new ChunkResult(0, $table->last, true);
        }
        $query = $source->table($from ?? $table->name)->select(array_values(array_unique([...$columns, ...$table->key ?? []])))->limit($size);
        if ($table->key !== null) {
            $this->afterLast($query, $table);
            foreach ($table->key as $column) {
                $query->orderBy($column);
            }
        } else {
            $this->orderWithoutKey($source, $query, $table, $columns)->offset($table->copied);
        }
        $perStatement = max(1, intdiv($demo->getDriverName() === 'sqlite' ? self::SQLITE_MAX_BINDINGS : self::MAX_BINDINGS, count($columns)));
        $coerce = $this->coercer($demo);
        [$count, $last, $batch] = [0, $table->last, []];
        $keep = array_flip($columns);
        // Stream rows and insert small batches so wide rows (JSON, long text) never fill PHP memory.
        foreach ($query->cursor() as $row) {
            $row = (array) $row;
            $count++;
            if ($table->key !== null) {
                $last = array_map(fn (string $column) => $row[$column], $table->key);
            }
            $batch[] = array_map($coerce, array_intersect_key($row, $keep));
            if (count($batch) >= $perStatement) {
                $demo->table($into ?? $table->name)->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $demo->table($into ?? $table->name)->insert($batch);
        }

        return new ChunkResult($count, $last, $count < $size);
    }

    /**
     * Set-based copy executed by one connection that can read the source and write the sandbox
     * (same server). No rows pass through PHP and column types are preserved exactly.
     */
    public function sql(Connection $connection, string $from, string $into, TableState $table, int $size, string $modifier = ''): ChunkResult
    {
        $columns = $table->columns ?? [];
        if ($columns === []) {
            return new ChunkResult(0, $table->last, true);
        }
        [$select, $upper] = $this->boundedSelect($connection, $from, $table, $size);
        $grammar = $connection->getQueryGrammar();
        $count = $connection->affectingStatement(
            'insert into '.$grammar->wrapTable($into).' ('.$grammar->columnize($columns).') '
            .($modifier !== '' ? $modifier.' ' : '').$select->select($columns)->toSql(),
            $select->getBindings(),
        );

        return new ChunkResult($count, $upper ?? $table->last, $upper === null);
    }

    /**
     * PostgreSQL to PostgreSQL across servers or databases: COPY in text format, which round-trips
     * every type (bytea, arrays, ranges) without PHP-side conversion.
     */
    public function postgresCopy(Connection $source, Connection $demo, TableState $table, int $size, ?string $from = null, ?string $into = null): ChunkResult
    {
        $columns = $table->columns ?? [];
        if ($columns === []) {
            return new ChunkResult(0, $table->last, true);
        }
        [$select, $upper] = $this->boundedSelect($source, $from ?? $table->name, $table, $size);
        $select->select($columns);
        // COPY cannot take bound parameters; inline them in one pass so quoted values are never rescanned.
        $bindings = $select->getBindings();
        $sql = preg_replace_callback('/\?/', function () use ($source, &$bindings): string {
            return $this->quote($source, array_shift($bindings));
        }, $select->toSql());
        $read = $source->getPdo();
        $rows = method_exists($read, 'copyToArray')
            ? $read->copyToArray('('.$sql.')', "\t", '\\\\N')
            : $read->pgsqlCopyToArray('('.$sql.')', "\t", '\\\\N');
        if ($rows === false) {
            throw new \RuntimeException('Could not read '.$table->name.' with COPY.');
        }
        if ($rows !== []) {
            $write = $demo->getPdo();
            $target = $demo->getQueryGrammar()->wrapTable($into ?? $table->name);
            $fields = $demo->getQueryGrammar()->columnize($columns);
            $written = method_exists($write, 'copyFromArray')
                ? $write->copyFromArray($target, $rows, "\t", '\\\\N', $fields)
                : $write->pgsqlCopyFromArray($target, $rows, "\t", '\\\\N', $fields);
            if ($written === false) {
                throw new \RuntimeException('Could not write '.$table->name.' with COPY.');
            }
        }

        return new ChunkResult(count($rows), $upper ?? $table->last, $upper === null);
    }

    /**
     * Select the next chunk as a key range (last, upper], found with one index-only lookup.
     * Tables without a primary key are copied in a single statement.
     *
     * @return array{0: Builder, 1: list<mixed>|null}
     */
    private function boundedSelect(Connection $connection, string $from, TableState $table, int $size): array
    {
        $select = $connection->table($from);
        if ($table->key === null) {
            return [$select, null];
        }
        $this->afterLast($select, $table);
        $bound = $connection->table($from)->select($table->key)->offset($size - 1)->limit(1);
        $this->afterLast($bound, $table);
        foreach ($table->key as $column) {
            $bound->orderBy($column);
            $select->orderBy($column);
        }
        $upper = $bound->first();
        if ($upper === null) {
            return [$select, null];
        }
        $upper = array_map(fn (string $column) => ((array) $upper)[$column], $table->key);
        count($table->key) === 1
            ? $select->where($table->key[0], '<=', $upper[0])
            : $select->whereRowValues($table->key, '<=', $upper);

        return [$select, $upper];
    }

    private function afterLast(Builder $query, TableState $table): void
    {
        if ($table->last === null) {
            return;
        }
        count($table->key) === 1
            ? $query->where($table->key[0], '>', $table->last[0])
            : $query->whereRowValues($table->key, '>', $table->last);
    }

    private function orderWithoutKey(Connection $source, Builder $query, TableState $table, array $columns): Builder
    {
        $schema = $source->getSchemaBuilder();
        $unique = collect($schema->getIndexes($table->name))->firstWhere('unique', true)['columns'] ?? null;
        // Some types have no ordering operator (PostgreSQL json, xml, geometric types).
        $orderable = collect($schema->getColumns($table->name))
            ->reject(fn (array $column): bool => in_array(strtolower($column['type_name']), self::UNORDERABLE, true))
            ->pluck('name')->all();
        foreach ($unique ?: array_values(array_intersect($columns, $orderable)) as $column) {
            $query->orderBy($column);
        }

        return $query;
    }

    /**
     * Portable-mode value conversion for drivers that do not share PHP value types.
     */
    private function coercer(Connection $demo): \Closure
    {
        $integerBooleans = $demo->getDriverName() !== 'pgsql';

        return fn ($value) => match (true) {
            is_bool($value) && $integerBooleans => (int) $value,
            is_resource($value) => stream_get_contents($value),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => $value,
        };
    }

    private function quote(Connection $connection, mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => $connection->getPdo()->quote((string) $value),
        };
    }
}
