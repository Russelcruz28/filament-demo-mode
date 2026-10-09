<?php

namespace DemoMode;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Copies source tables into the sandbox in resumable chunks and reports progress.
 */
class SandboxCopier
{
    // Bound parameters per INSERT, safe for SQLite builds older than 3.32.
    private const SQLITE_MAX_BINDINGS = 999;

    public function plan(Connection $source, array $tables): array
    {
        $schema = $source->getSchemaBuilder();

        return array_map(function (string $name) use ($source, $schema): array {
            $indexes = collect($schema->getIndexes($name));
            $primary = $indexes->firstWhere('primary', true)['columns'] ?? null;
            $order = $primary ?? $indexes->firstWhere('unique', true)['columns'] ?? $schema->getColumnListing($name);

            return [
                'name' => $name,
                // Single-column primary keys page by key; other tables page by ordered offset.
                'key' => is_array($primary) && count($primary) === 1 ? $primary[0] : null,
                'order' => $order,
                'total' => $source->table($name)->count(),
                'copied' => 0,
                'last' => null,
            ];
        }, $tables);
    }

    public function copyChunk(Connection $source, Connection $demo, array $state): array
    {
        $index = $state['index'];
        if ($index >= count($state['tables'])) {
            return [...$state, 'phase' => 'verify'];
        }
        $table = $state['tables'][$index];
        $size = max(1, (int) config('demo-mode.provisioning.chunk_size', 1000));
        $rows = $this->chunkQuery($source, $table, $size)->get();
        $columns = array_flip($demo->getSchemaBuilder()->getColumnListing($table['name']));
        $records = $rows->map(fn ($row): array => array_intersect_key((array) $row, $columns))->all();
        $perStatement = max(1, intdiv(self::SQLITE_MAX_BINDINGS, max(1, count($columns))));
        if ($columns !== []) {
            $demo->getSchemaBuilder()->withoutForeignKeyConstraints(fn () => $demo->transaction(function () use ($demo, $table, $records, $perStatement): void {
                foreach (array_chunk($records, $perStatement) as $batch) {
                    $demo->table($table['name'])->insert($batch);
                }
            }));
        }
        $tables = $state['tables'];
        $tables[$index] = [
            ...$table,
            'copied' => $table['copied'] + $rows->count(),
            'last' => $table['key'] !== null && $rows->isNotEmpty() ? $rows->last()->{$table['key']} : $table['last'],
        ];

        return [...$state, 'tables' => $tables, 'index' => $rows->count() < $size ? $index + 1 : $index];
    }

    public function progress(array $state): array
    {
        $total = array_sum(array_column($state['tables'], 'total'));
        $copied = array_sum(array_map(fn (array $table): int => min($table['copied'], $table['total']), $state['tables']));
        // Schema creation and relationship checks each count as one unit of work.
        $done = ($state['phase'] === 'migrate' ? 0 : 1) + $copied + ($state['phase'] === 'ready' ? 1 : 0);
        $table = $state['tables'][$state['index']] ?? null;

        return [
            'status' => 'running',
            'percent' => min(99, intdiv($done * 100, $total + 2)),
            'message' => match (true) {
                $state['phase'] === 'migrate' => 'Creating the demo database',
                $state['phase'] === 'copy' && $table !== null => sprintf('Copying %s (%s of %s rows)',
                    Str::of($table['name'])->replace('_', ' ')->lower(), number_format($table['copied']), number_format($table['total'])),
                $state['phase'] === 'copy' => 'Copying data',
                default => 'Checking relationships',
            },
            'copied' => $copied,
            'total' => $total,
        ];
    }

    private function chunkQuery(Connection $source, array $table, int $size): Builder
    {
        $query = $source->table($table['name'])->limit($size);
        if ($table['key'] !== null) {
            return $query->orderBy($table['key'])
                ->when($table['last'] !== null, fn ($query) => $query->where($table['key'], '>', $table['last']));
        }
        foreach ($table['order'] as $column) {
            $query->orderBy($column);
        }

        return $query->offset($table['copied']);
    }
}
