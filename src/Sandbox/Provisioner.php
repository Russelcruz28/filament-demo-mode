<?php

namespace DemoMode\Sandbox;

use DemoMode\ProvisioningFailed;
use DemoMode\SandboxCopier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The resumable provisioning pipeline: prepare → schema → strip → copy → finalize → verify → ready.
 *
 * Each step() runs one unit of work and returns the next state, which the caller persists between
 * short progress requests. Activation (session swap) stays in DemoManager.
 */
class Provisioner
{
    public const PHASES = ['prepare', 'schema', 'strip', 'copy', 'finalize', 'verify', 'ready'];

    // Fixed units of work besides copied rows: prepare, schema, strip, finalize, verify.
    private const FIXED_UNITS = 5;

    public function __construct(private SandboxDrivers $drivers, private SandboxCopier $copier) {}

    /**
     * @param  array{sample?: bool, collect?: bool}  $options  sample: one chunk per table; collect: report problems instead of failing.
     */
    public function state(SandboxRef $ref, Connection $source, array $tables, array $options = []): array
    {
        return [
            'owner' => $ref->owner, 'source' => $source->getName(), 'ref' => $ref->toArray(),
            'schema' => $this->drivers->schemaSource()->key(), 'phase' => 'prepare', 'index' => 0,
            'plan' => $this->copier->plan($source, $tables)->toArray(), 'warnings' => [], 'options' => $options,
        ];
    }

    public function step(array $state): array
    {
        $ref = SandboxRef::fromArray($state['ref']);
        $driver = $this->drivers->for($ref);
        $source = DB::connection($state['source']);
        $plan = TablePlan::fromArray($state['plan']);
        $this->drivers->connect($ref);
        $demo = DB::connection('demo');
        $phase = $state['phase'];
        switch ($phase) {
            case 'prepare':
                $driver->create($ref, $source);
                break;
            case 'schema':
                $this->drivers->schemaSource($state['schema'] ?? null)->build($ref, $driver, $source, $plan);
                break;
            case 'strip':
                $driver->beforeCopy($ref, $demo, $plan);
                if (! ($plan->meta['data_cloned'] ?? false)) {
                    $state['warnings'] = [...$state['warnings'], ...$this->matchColumns($source, $demo, $plan, $state['options'] ?? [])];
                }
                break;
            case 'copy':
                $state['index'] = $this->copy($ref, $driver, $source, $demo, $plan, (int) $state['index'], $state['options'] ?? []);
                if ($state['index'] < count($plan->tables)) {
                    return [...$state, 'plan' => $plan->toArray()];
                }
                break;
            case 'finalize':
                $driver->afterCopy($ref, $demo, $plan);
                break;
            case 'verify':
                if (($violations = $driver->verify($ref, $demo, $plan)) !== []) {
                    if (! ($state['options']['collect'] ?? false)) {
                        throw new ProvisioningFailed('Demo data has missing relationships. Select the related models and try again. ('
                            .implode('; ', array_slice($violations, 0, 5)).(count($violations) > 5 ? '; …' : '').')');
                    }
                    $state['warnings'] = [...$state['warnings'], ...array_map(fn ($violation) => 'Missing relationship: '.$violation, $violations)];
                }
                break;
            default:
                return $state;
        }

        return [...$state, 'plan' => $plan->toArray(), 'phase' => self::PHASES[array_search($phase, self::PHASES, true) + 1]];
    }

    private function copy(SandboxRef $ref, $driver, Connection $source, Connection $demo, TablePlan $plan, int $index, array $options): int
    {
        // A cloned SQLite file already holds every selected row.
        if ($plan->meta['data_cloned'] ?? false) {
            foreach ($plan->tables as $table) {
                [$table->copied, $table->done] = [$table->total, true];
            }

            return count($plan->tables);
        }
        if (! isset($plan->tables[$index])) {
            return count($plan->tables);
        }
        $table = $plan->tables[$index];
        $result = $driver->copyChunk($ref, $source, $demo, $table, (int) config('demo-mode.provisioning.chunk_size', 1000));
        $table->copied += $result->count;
        $table->last = $result->last;
        $table->done = $result->done || ($options['sample'] ?? false);

        return $table->done ? $index + 1 : $index;
    }

    /**
     * Decide which columns are copied and report source columns the sandbox cannot hold, instead of
     * silently dropping their data. Generated columns are never written.
     *
     * @return list<string> warnings
     */
    private function matchColumns(Connection $source, Connection $demo, TablePlan $plan, array $options): array
    {
        [$missingTables, $missingColumns] = [[], []];
        foreach ($plan->tables as $table) {
            $sandbox = collect($demo->getSchemaBuilder()->getColumns($table->name));
            if ($sandbox->isEmpty()) {
                $missingTables[] = $table->name;

                continue;
            }
            $readable = collect($source->getSchemaBuilder()->getColumns($table->name))->whereNull('generation')->pluck('name');
            $table->columns = $readable->intersect($sandbox->whereNull('generation')->pluck('name'))->values()->all();
            foreach ($readable->diff($sandbox->pluck('name')) as $column) {
                $missingColumns[] = $table->name.'.'.$column;
            }
        }
        if ($missingTables !== []) {
            throw new ProvisioningFailed('Tables missing from the demo schema: '.implode(', ', $missingTables).'. Their migrations may not run on this sandbox driver, or the migration history has drifted. Try the "clone" or "dump" schema source.');
        }
        if ($missingColumns === []) {
            return [];
        }
        $message = 'Columns missing from the demo schema (their data is not copied): '.implode(', ', $missingColumns).'.';
        if (($options['collect'] ?? false) || config('demo-mode.sandbox.on_column_mismatch', 'fail') === 'warn') {
            return [$message];
        }

        throw new ProvisioningFailed($message.' Update the migrations, use the "clone" or "dump" schema source, or set demo-mode.sandbox.on_column_mismatch to "warn".');
    }

    public function progress(array $state): array
    {
        $plan = TablePlan::fromArray($state['plan'] ?? []);
        $phase = $state['phase'] === 'migrate' ? 'schema' : $state['phase'];
        $total = array_sum(array_map(fn (TableState $table): int => $table->total, $plan->tables));
        $copied = array_sum(array_map(fn (TableState $table): int => min($table->copied, $table->total), $plan->tables));
        $fixed = ['prepare' => 0, 'schema' => 1, 'strip' => 2, 'copy' => 3, 'finalize' => 3, 'verify' => 4, 'ready' => 5][$phase] ?? 0;
        $table = $plan->tables[$state['index'] ?? 0] ?? null;

        return [
            'status' => 'running',
            'percent' => min(99, intdiv(($fixed + $copied) * 100, $total + self::FIXED_UNITS)),
            'message' => match (true) {
                $phase === 'prepare' => 'Creating the demo database',
                $phase === 'schema' => 'Building the demo schema',
                $phase === 'strip' => 'Preparing tables',
                $phase === 'copy' && $table !== null => sprintf('Copying %s (%s of %s rows)',
                    Str::of($table->name)->replace('_', ' ')->lower(), number_format($table->copied), number_format($table->total)),
                $phase === 'copy' => 'Copying data',
                $phase === 'finalize' => 'Restoring relationships',
                default => 'Checking relationships',
            },
            'copied' => $copied,
            'total' => $total,
            'warnings' => $state['warnings'] ?? [],
        ];
    }
}
