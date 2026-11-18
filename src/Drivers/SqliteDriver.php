<?php

namespace DemoMode\Drivers;

use DemoMode\Contracts\ClonesSchema;
use DemoMode\DemoManager;
use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\ChunkResult;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use DemoMode\Sandbox\TableState;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * One SQLite file per sandbox: <root>/<token>/database.sqlite. Portable mode for non-SQLite sources.
 */
class SqliteDriver extends Driver implements ClonesSchema
{
    public function key(): string
    {
        return 'sqlite';
    }

    public function supports(Connection $source): Capability
    {
        if (! extension_loaded('pdo_sqlite')) {
            return Capability::unsupported('The pdo_sqlite extension is required for SQLite sandboxes.');
        }

        return $source->getDriverName() === 'sqlite' ? Capability::ok() : Capability::warning(
            'Portable mode: the '.$source->getDriverName().' source is copied into SQLite. Migrations and runtime SQL must be SQLite-compatible; use driver "auto" to keep the source engine.',
        );
    }

    public function ref(string $token, ?string $owner, Connection $source): SandboxRef
    {
        return new SandboxRef($token, $this->key(), app(DemoManager::class)->directory($token).'/database.sqlite', $owner, $source->getName());
    }

    public function create(SandboxRef $ref, Connection $source): void
    {
        File::ensureDirectoryExists(dirname($ref->container), 0700);
        if (! File::exists($ref->container)) {
            File::put($ref->container, '');
        }
    }

    public function exists(SandboxRef $ref): bool
    {
        return is_file($ref->container);
    }

    public function connectionConfig(SandboxRef $ref, array $sourceConfig): array
    {
        return ['driver' => 'sqlite', 'database' => $ref->container, 'prefix' => '',
            'foreign_key_constraints' => true, 'busy_timeout' => 10000];
    }

    public function beforeCopy(SandboxRef $ref, Connection $demo, TablePlan $plan): void
    {
        // Migrations can insert defaults. The sandbox must match the selected source data.
        // A cloned file already holds the selected rows, so only unselected tables are emptied.
        $keep = ($plan->meta['data_cloned'] ?? false) ? $plan->names() : [];
        $demo->getSchemaBuilder()->withoutForeignKeyConstraints(fn () => $demo->transaction(function () use ($demo, $keep): void {
            foreach ($demo->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
                if ($table !== 'migrations' && ! in_array($table, $keep, true)) {
                    $demo->table($table)->delete();
                }
            }
        }));
    }

    public function copyChunk(SandboxRef $ref, Connection $source, Connection $demo, TableState $table, int $size): ChunkResult
    {
        return $demo->getSchemaBuilder()->withoutForeignKeyConstraints(fn (): ChunkResult => $demo->transaction(
            fn (): ChunkResult => $this->copier->stream($source, $demo, $table, $this->size($size)),
        ));
    }

    public function afterCopy(SandboxRef $ref, Connection $demo, TablePlan $plan): void {}

    public function verify(SandboxRef $ref, Connection $demo, TablePlan $plan): array
    {
        return array_values(array_unique(array_map(
            fn ($row): string => $row->table.' → '.$row->parent,
            $demo->select('PRAGMA foreign_key_check'),
        )));
    }

    public function drop(SandboxRef $ref): void
    {
        DB::purge('demo');
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            File::delete($ref->container.$suffix);
        }
    }

    public function list(): array
    {
        return array_values(array_filter(File::glob(config('demo-mode.root').'/*/database.sqlite') ?: [], 'is_file'));
    }

    public function canClone(Connection $source): Capability
    {
        return $source->getDriverName() === 'sqlite' ? Capability::ok()
            : Capability::unsupported('The clone schema source needs a SQLite source for the sqlite driver. Use "migrations" or "dump", or a driver matching the '.$source->getDriverName().' source.');
    }

    /**
     * VACUUM INTO copies schema and rows in one pass; unselected tables are emptied in beforeCopy().
     */
    public function cloneSchema(SandboxRef $ref, Connection $source, Connection $demo, TablePlan $plan): void
    {
        DB::purge('demo');
        File::delete($ref->container);
        $source->statement('VACUUM INTO ?', [$ref->container]);
        $plan->meta['data_cloned'] = true;
    }
}
