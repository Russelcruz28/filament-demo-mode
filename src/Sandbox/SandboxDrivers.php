<?php

namespace DemoMode\Sandbox;

use Closure;
use DemoMode\Contracts\SandboxDriver;
use DemoMode\Contracts\SchemaSource;
use DemoMode\Drivers\MySqlDatabaseDriver;
use DemoMode\Drivers\PostgresDatabaseDriver;
use DemoMode\Drivers\PostgresSchemaDriver;
use DemoMode\Drivers\SqliteDriver;
use DemoMode\Schema\CloneSource;
use DemoMode\Schema\DumpSource;
use DemoMode\Schema\MigrationsSource;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves sandbox drivers and schema sources from configuration.
 */
class SandboxDrivers
{
    private const DRIVERS = [
        'sqlite' => SqliteDriver::class,
        'pgsql-schema' => PostgresSchemaDriver::class,
        'pgsql-database' => PostgresDatabaseDriver::class,
        'mysql-database' => MySqlDatabaseDriver::class,
    ];

    private const SOURCES = [
        'migrations' => MigrationsSource::class,
        'dump' => DumpSource::class,
        'clone' => CloneSource::class,
    ];

    /** @var array<string, class-string<SandboxDriver>|Closure> */
    private array $custom = [];

    public function extend(string $key, string|Closure $driver): void
    {
        $this->custom[$key] = $driver;
    }

    public function make(string $key): SandboxDriver
    {
        $driver = $this->custom[$key] ?? self::DRIVERS[$key] ?? $key;
        $driver = $driver instanceof Closure ? $driver(app()) : (class_exists($driver) ? app($driver) : null);
        if (! $driver instanceof SandboxDriver) {
            throw new \InvalidArgumentException("Unknown demo sandbox driver [{$key}].");
        }

        return $driver;
    }

    /** The configured driver for a source connection; "auto" follows the source engine. */
    public function resolve(Connection $source): SandboxDriver
    {
        return $this->make($this->key($source));
    }

    public function key(Connection $source): string
    {
        $key = (string) config('demo-mode.sandbox.driver', 'sqlite');

        return $key === 'auto' ? self::autoKey($source->getDriverName()) : $key;
    }

    public static function autoKey(string $sourceDriver): string
    {
        return match ($sourceDriver) {
            'pgsql' => 'pgsql-schema',
            'mysql', 'mariadb' => 'mysql-database',
            default => 'sqlite',
        };
    }

    /** The database engine family a driver writes, used to match dumps and SQL dialects. */
    public static function family(SandboxDriver $driver): ?string
    {
        return match (true) {
            $driver instanceof SqliteDriver => 'sqlite',
            $driver instanceof PostgresSchemaDriver, $driver instanceof PostgresDatabaseDriver => 'pgsql',
            $driver instanceof MySqlDatabaseDriver => 'mysql',
            default => null,
        };
    }

    public static function sourceFamily(Connection $source): string
    {
        return $source->getDriverName() === 'mariadb' ? 'mysql' : $source->getDriverName();
    }

    public function for(SandboxRef $ref): SandboxDriver
    {
        return $this->make($ref->driver);
    }

    public function schemaSource(?string $key = null): SchemaSource
    {
        $key ??= (string) config('demo-mode.sandbox.schema', 'migrations');
        $source = self::SOURCES[$key] ?? $key;
        $source = class_exists($source) ? app($source) : null;
        if (! $source instanceof SchemaSource) {
            throw new \InvalidArgumentException("Unknown demo schema source [{$key}].");
        }

        return $source;
    }

    /**
     * Point the "demo" connection at a sandbox. Connections carry the token so the runtime guard can
     * tell sandbox connections from stale production ones that share a name.
     */
    public function connect(SandboxRef $ref): void
    {
        DB::purge('demo');
        $source = (array) config('database.connections.'.($ref->source ?? config('database.default')), []);
        config(['database.connections.demo' => [
            ...$this->for($ref)->connectionConfig($ref, $source), 'demo_mode' => $ref->token,
        ]]);
    }
}
