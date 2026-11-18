<?php

namespace DemoMode\Commands;

use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\Contracts\MetadataStore;
use DemoMode\Contracts\RoleSwitcher;
use DemoMode\DemoManager;
use DemoMode\Drivers\PostgresSchemaDriver;
use DemoMode\Metadata\DatabaseMetadataStore;
use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\ConnectionPolicy;
use DemoMode\Sandbox\Provisioner;
use DemoMode\Sandbox\SandboxDrivers;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DoctorDemoMode extends Command
{
    protected $signature = 'demo-mode:doctor {--provision : Build a throwaway sandbox end-to-end (one chunk per table), report problems, then drop it}';

    protected $description = 'Check whether Demo Mode can work for this application and database';

    private const DIALECT = [
        'pgsql' => '/plpgsql|gen_random_uuid|create\s+(or\s+replace\s+)?function|create\s+extension|distinct\s+on|\bilike\b|\busing\s+gin\b|\bjsonb\b|\bregclass\b/i',
        'mysql' => '/\bengine\s*=|\bauto_increment\b|\bdelimiter\b|create\s+(definer\s*=\s*\S+\s+)?procedure|\bfulltext\b/i',
        'sqlite' => '/\bpragma\b|\bautoincrement\b|without\s+rowid/i',
    ];

    private const SDK_HINTS = [
        'GuzzleHttp\\Client' => 'Guzzle client (bypasses Http::preventStrayRequests)',
        'Minishlink\\WebPush' => 'Web push',
        'RdKafka\\' => 'Kafka (rdkafka)',
        'Junges\\Kafka' => 'Kafka',
        'Aws\\' => 'AWS SDK',
        'Kreait\\Firebase' => 'Firebase',
        'Twilio\\Rest\\Client' => 'Twilio',
        'Stripe\\StripeClient' => 'Stripe',
        'PhpAmqpLib\\' => 'RabbitMQ',
    ];

    private bool $failed = false;

    public function handle(): int
    {
        // Artisan reuses command instances.
        $this->failed = false;
        $this->installation();
        try {
            $source = DB::connection();
            $source->getPdo();
        } catch (\Throwable $exception) {
            $this->report('FAIL', 'Database reachable', $exception->getMessage());

            return self::FAILURE;
        }
        $drivers = app(SandboxDrivers::class);
        try {
            $driver = $drivers->resolve($source);
            $schema = $drivers->schemaSource();
        } catch (\InvalidArgumentException $exception) {
            $this->report('FAIL', 'Sandbox configuration', $exception->getMessage());

            return self::FAILURE;
        }
        $this->resolution($source, $driver->key(), $schema->key());
        $this->newLine();
        $this->line('<comment>Capabilities</comment>');
        $this->capability('Sandbox driver '.$driver->key(), $this->guard(fn () => $driver->supports($source)));
        $this->capability('Schema source '.$schema->key(), $this->guard(fn () => $schema->supports($driver, $source)));
        $this->dedicatedRole($driver, $source);
        $this->scanMigrations($driver, $source);
        $this->scanApplication();
        if ($this->option('provision')) {
            $this->provision($source, $driver);
        }

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function installation(): void
    {
        $this->line('<comment>Installation</comment>');
        $adapter = config('demo-mode.adapter');
        $this->check('Application adapter', is_string($adapter) && is_a($adapter, ApplicationAdapter::class, true));
        $this->check('Standalone role switcher', is_string($adapter) && is_a($adapter, RoleSwitcher::class, true));
        $root = config('demo-mode.root');
        $parent = $root;
        while (! File::exists($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        $this->check('Sandbox storage writable', File::isDirectory($parent) && is_writable($parent));
        try {
            $builder = DB::connection()->getSchemaBuilder();
            $this->check('Settings migration', $builder->hasTable('demo_settings'));
            if (config('demo-mode.demo_users.enabled', true) && $builder->hasTable('demo_settings')) {
                $this->check('Demo users migration', $builder->hasColumn('demo_settings', 'users'), 'Run php artisan migrate');
            }
            if (app(MetadataStore::class) instanceof DatabaseMetadataStore) {
                $this->check('Sandbox metadata table', $builder->hasTable(DatabaseMetadataStore::TABLE), 'Run php artisan migrate');
            }
        } catch (\Throwable) {
            // Reported by the reachability check.
        }
    }

    private function resolution(Connection $source, string $driver, string $schema): void
    {
        $this->newLine();
        $this->line('<comment>Resolution</comment>');
        $this->line("  Source:          {$source->getName()} ({$source->getDriverName()})");
        $configured = config('demo-mode.sandbox.driver');
        $this->line('  Sandbox driver:  '.$driver.($configured === 'auto' ? ' (auto)' : ''));
        $this->line('  Schema source:   '.$schema);
        $this->line('  Copy strategy:   '.config('demo-mode.sandbox.copy', 'auto'));
        $this->line('  Metadata / lock: '.config('demo-mode.metadata', 'file').' / '.config('demo-mode.lock', 'file'));
        $policies = collect(app(ConnectionPolicy::class)->resolve($source->getName()))
            ->map(fn (string $policy, string $name): string => "{$name}={$policy}")->implode(', ');
        $this->line('  Connections:     '.$policies);
        if (SandboxDrivers::autoKey($source->getDriverName()) !== $driver && ! class_exists($driver)) {
            $this->report('WARN', 'Driver', "The source is {$source->getDriverName()}; driver \"auto\" would use ".SandboxDrivers::autoKey($source->getDriverName()).'.');
        }
        if (! in_array($source->getDriverName(), ['sqlite', 'pgsql', 'mysql', 'mariadb'], true)) {
            $this->report('WARN', 'Source', $source->getDriverName().' is not supported natively; sandboxes use SQLite portable mode.');
        }
    }

    private function dedicatedRole($driver, Connection $source): void
    {
        if (! $driver instanceof PostgresSchemaDriver || ! ($name = config('demo-mode.sandbox.connection'))) {
            return;
        }
        $readable = $this->guard(fn () => DB::connection($name)->selectOne(
            "select count(*) as tables from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where c.relkind in ('r', 'p', 'v', 'm') and n.nspname = ? and has_table_privilege(current_user, c.oid, 'SELECT')",
            [$source->selectOne('select current_schema() as name')->name])->tables);
        if ($readable instanceof Capability) {
            $this->capability('Dedicated sandbox role', $readable);
        } elseif ($readable > 0) {
            $this->report('WARN', 'Dedicated sandbox role', "The role can read {$readable} production tables, so a table missing from a sandbox would leak production rows. Revoke its table privileges.");
        } else {
            $this->report('PASS', 'Dedicated sandbox role cannot read production tables');
        }
    }

    /**
     * Static checks on migrations: connection overrides, schema-qualified DDL and SQL in another dialect.
     */
    private function scanMigrations($driver, Connection $source): void
    {
        $this->newLine();
        $this->line('<comment>Migrations</comment>');
        $paths = array_unique(array_filter([...app('migrator')->paths(), database_path('migrations')], 'is_dir'));
        $files = collect($paths)->flatMap(fn ($path) => File::files($path))->filter(fn ($file) => $file->getExtension() === 'php');
        $policies = app(ConnectionPolicy::class)->resolve($source->getName());
        $family = SandboxDrivers::family($driver);
        $sourceSchema = $source->getDriverName() === 'pgsql' ? $source->selectOne('select current_schema() as name')->name : null;
        $findings = 0;
        foreach ($files as $file) {
            $code = File::get($file->getPathname());
            $name = $file->getFilename();
            if (preg_match('/function\s+getConnection\s*\(|protected\s+\$connection\s*=/', $code)) {
                $connection = $this->migrationConnection($code);
                $blocked = $connection !== null && ($policies[$connection] ?? ConnectionPolicy::BLOCK) === ConnectionPolicy::BLOCK;
                $this->report($blocked ? 'FAIL' : 'INFO', $name, $blocked
                    ? "runs on the \"{$connection}\" connection, which is blocked in the sandbox. Add it to demo-mode.connections.sandbox or allow."
                    : 'overrides its connection; connections to the source database resolve to the sandbox.');
                $findings++;
            }
            if ($driver instanceof PostgresSchemaDriver && $sourceSchema !== null
                && preg_match('/\b(create|alter|drop)\s+(or\s+replace\s+)?(table|function|procedure|view|trigger|index|sequence|type)\s+(if\s+(not\s+)?exists\s+)?"?'.preg_quote($sourceSchema, '/').'"?\./i', $code)) {
                $safe = (bool) config('demo-mode.sandbox.connection');
                $this->report($safe ? 'WARN' : 'FAIL', $name, "contains DDL qualified with \"{$sourceSchema}.\"; in a schema sandbox it targets production"
                    .($safe ? ' (the dedicated role makes it fail instead).' : '. Configure a dedicated sandbox role or remove the qualifier.'));
                $findings++;
            }
            if ($family !== null && $family !== SandboxDrivers::sourceFamily($source)) {
                $raw = preg_match('/\b(statement|unprepared|raw|selectRaw|whereRaw)\s*\(/', $code);
                foreach (self::DIALECT as $dialect => $pattern) {
                    if ($raw && $dialect !== $family && preg_match($pattern, $code)) {
                        $this->report('WARN', $name, "contains {$dialect}-specific SQL, which may fail on the {$family} sandbox.");
                        $findings++;
                        break;
                    }
                }
            }
        }
        if ($findings === 0) {
            $this->report('PASS', 'No connection overrides, schema-qualified DDL or foreign-dialect SQL found in '.$files->count().' migrations');
        }
    }

    /** The connection a migration names: a literal, or config('...') as Telescope does. */
    private function migrationConnection(string $code): ?string
    {
        if (preg_match('/protected\s+\$connection\s*=\s*[\'"]([^\'"]+)[\'"]/', $code, $match)
            || preg_match('/function\s+getConnection\s*\([^)]*\)[^{]*\{\s*return\s+[\'"]([^\'"]+)[\'"]/', $code, $match)) {
            return $match[1];
        }
        if (preg_match('/function\s+getConnection\s*\([^)]*\)[^{]*\{\s*return\s+config\(\s*[\'"]([^\'"]+)[\'"]/', $code, $match)) {
            $name = config($match[1]);

            return is_string($name) ? $name : null;
        }

        return null;
    }

    private function scanApplication(): void
    {
        $bound = array_keys((array) config('demo-mode.sandbox_bindings', []));
        $hits = [];
        foreach (is_dir(app_path()) ? File::allFiles(app_path()) : [] as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = File::get($file->getPathname());
            foreach (self::SDK_HINTS as $needle => $label) {
                if (str_contains($code, $needle) && ! collect($bound)->contains(fn ($class) => str_starts_with(ltrim($class, '\\'), ltrim($needle, '\\')))) {
                    $hits[$label][] = $file->getRelativePathname();
                }
            }
        }
        if ($hits === []) {
            return;
        }
        $this->newLine();
        $this->line('<comment>External clients (hints)</comment>');
        foreach ($hits as $label => $files) {
            $this->report('WARN', $label, 'used in '.implode(', ', array_slice(array_unique($files), 0, 3))
                .'. Wrap it in a service and swap it through demo-mode.sandbox_bindings.');
        }
    }

    /**
     * Provision a throwaway sandbox with one chunk per table, collect column mismatches and
     * foreign key problems instead of failing on the first one, then drop it.
     */
    private function provision(Connection $source, $driver): void
    {
        $this->newLine();
        $this->line('<comment>Provisioning check</comment>');
        $manager = app(DemoManager::class);
        $provisioner = app(Provisioner::class);
        $token = (string) Str::uuid();
        $ref = $driver->ref($token, null, $source);
        $metadata = app(MetadataStore::class);
        $schema = $source->getSchemaBuilder();
        $tables = array_values(array_diff(
            $schema->getTableListing($schema->getCurrentSchemaListing(), schemaQualified: false),
            config('demo-mode.excluded_tables', []),
        ));
        try {
            File::ensureDirectoryExists($manager->directory($token), 0700);
            $state = $provisioner->state($ref, $source, $tables, ['sample' => true, 'collect' => true]);
            $metadata->saveProvisioning($token, $state);
            while ($state['phase'] !== 'ready') {
                $state = $provisioner->step($state);
                $metadata->saveProvisioning($token, $state);
            }
            $this->report('PASS', 'Sandbox built: schema, '.count($tables).' tables sampled, finalize and verify completed');
            foreach ($state['warnings'] as $warning) {
                $this->report('WARN', str_starts_with($warning, 'Missing relationship') ? 'Sampled copy' : 'Schema', $warning
                    .(str_starts_with($warning, 'Missing relationship') ? ' (only one chunk per table was copied; this may be a sampling artifact)' : ''));
            }
        } catch (\Throwable $exception) {
            $this->report('FAIL', 'Sandbox build', $exception->getMessage());
        } finally {
            try {
                DB::purge('demo');
                $driver->drop($ref);
            } catch (\Throwable $exception) {
                $this->report('WARN', 'Cleanup', 'Could not drop the throwaway sandbox '.$ref->container.': '.$exception->getMessage());
            }
            $metadata->forgetProvisioning($token);
            File::deleteDirectory($manager->directory($token));
        }
    }

    private function guard(\Closure $check): mixed
    {
        try {
            return $check();
        } catch (\Throwable $exception) {
            return Capability::unsupported($exception->getMessage());
        }
    }

    private function capability(string $name, Capability $capability): void
    {
        if ($capability->reasons === []) {
            $this->report($capability->supported() ? 'PASS' : 'FAIL', $name);
        }
        foreach ($capability->reasons as $reason) {
            $this->report($capability->status === Capability::UNSUPPORTED ? 'FAIL' : 'WARN', $name, $reason);
        }
    }

    private function check(string $name, bool $passed, ?string $hint = null): void
    {
        $this->report($passed ? 'PASS' : 'FAIL', $name, $passed ? null : $hint);
    }

    private function report(string $status, string $name, ?string $detail = null): void
    {
        $this->failed = $this->failed || $status === 'FAIL';
        $style = ['PASS' => 'info', 'FAIL' => 'error', 'WARN' => 'comment', 'INFO' => 'fg=default'][$status];
        $this->line("<{$style}>{$status}</> {$name}".($detail ? ': '.$detail : ''));
    }
}
