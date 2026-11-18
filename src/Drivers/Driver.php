<?php

namespace DemoMode\Drivers;

use DemoMode\Contracts\SandboxDriver;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\SandboxCopier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

abstract class Driver implements SandboxDriver
{
    public function __construct(protected SandboxCopier $copier) {}

    public function ref(string $token, ?string $owner, Connection $source): SandboxRef
    {
        return new SandboxRef($token, $this->key(), $this->containerName($token), $owner, $source->getName());
    }

    /** Server-side container names: a configurable prefix plus 12 hex characters (fits PostgreSQL's 63-char limit). */
    protected function containerName(string $token): string
    {
        return $this->prefix().substr(hash('sha256', $token), 0, 12);
    }

    protected function prefix(): string
    {
        return (string) config('demo-mode.sandbox.prefix', 'demo_');
    }

    protected function owns(string $container): bool
    {
        return (bool) preg_match('/^'.preg_quote($this->prefix(), '/').'[0-9a-f]{12}$/', $container);
    }

    /** The connection that creates and drops containers: the dedicated sandbox connection, else the source. */
    protected function admin(?SandboxRef $ref = null): Connection
    {
        return DB::connection(config('demo-mode.sandbox.connection') ?: ($ref?->source ?? config('database.default')));
    }

    /** Connection config the sandbox is cloned from: the dedicated sandbox connection, else the source. */
    protected function baseConfig(array $sourceConfig): array
    {
        $name = config('demo-mode.sandbox.connection');

        return $name ? (array) config('database.connections.'.$name, []) : $sourceConfig;
    }

    protected function strategy(): string
    {
        return (string) config('demo-mode.sandbox.copy', 'auto');
    }

    protected function size(int $size): int
    {
        return max(1, $size);
    }

    protected function wrap(Connection $connection, string $identifier): string
    {
        return $connection->getQueryGrammar()->wrap($identifier);
    }
}
