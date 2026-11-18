<?php

namespace DemoMode\Sandbox;

use Illuminate\Support\ConfigurationUrlParser;

/**
 * Decides what each configured connection does while a demo is active:
 *  - sandbox: the connection name resolves to the sandbox (e.g. DB::connection('pgsql'), Telescope's migration)
 *  - block:   any query throws
 *  - allow:   passes through untouched (explicitly opted-in read-only reference databases)
 */
class ConnectionPolicy
{
    public const SANDBOX = 'sandbox';

    public const BLOCK = 'block';

    public const ALLOW = 'allow';

    /** @return array<string, string> */
    public function resolve(string $source): array
    {
        $auto = config('demo-mode.connections.policy', 'auto') === 'auto';
        $identity = $this->identity($source);
        $policies = [];
        foreach (array_keys((array) config('database.connections', [])) as $name) {
            if ($name === 'demo') {
                continue;
            }
            $policies[$name] = match (true) {
                in_array($name, (array) config('demo-mode.connections.allow', []), true) => self::ALLOW,
                in_array($name, (array) config('demo-mode.connections.sandbox', []), true) => self::SANDBOX,
                $auto && ($name === $source || ($identity !== null && $this->identity($name) === $identity)) => self::SANDBOX,
                default => self::BLOCK,
            };
        }

        return $policies;
    }

    /**
     * The physical database a connection points at: driver family + host + port + database.
     * In-memory SQLite databases are private to their connection, so they have no shared identity.
     */
    public function identity(string $name): ?string
    {
        $config = config('database.connections.'.$name);
        if (! is_array($config)) {
            return null;
        }
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        $driver = ($config['driver'] ?? '') === 'mariadb' ? 'mysql' : ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');
        if ($driver === 'sqlite') {
            if ($database === '' || $database === ':memory:' || str_contains($database, 'mode=memory')) {
                return null;
            }

            return 'sqlite|'.(realpath($database) ?: $database);
        }
        $host = $config['write']['host'] ?? $config['host'] ?? '';
        $host = is_array($host) ? (string) reset($host) : (string) $host;
        $host = in_array($host, ['localhost', '::1'], true) ? '127.0.0.1' : strtolower($host);
        $port = (string) ($config['port'] ?? match ($driver) {
            'pgsql' => 5432, 'mysql' => 3306, 'sqlsrv' => 1433, default => ''
        });

        return implode('|', [$driver, $host, $port, $database]);
    }
}
