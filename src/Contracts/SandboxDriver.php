<?php

namespace DemoMode\Contracts;

use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\ChunkResult;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use DemoMode\Sandbox\TableState;
use Illuminate\Database\Connection;

/**
 * Every engine-specific sandbox operation: container lifecycle, bulk copy and integrity checks.
 */
interface SandboxDriver
{
    /** The key stored with each sandbox, e.g. "pgsql-schema". Custom drivers may return their class name. */
    public function key(): string;

    /** Whether this driver can host a sandbox for the source connection on this server. */
    public function supports(Connection $source): Capability;

    /** Name the container for a new sandbox. Containers must be derivable from the token unless the driver stores them. */
    public function ref(string $token, ?string $owner, Connection $source): SandboxRef;

    /** Create the empty container: file, schema or database. Idempotent. */
    public function create(SandboxRef $ref, Connection $source): void;

    public function exists(SandboxRef $ref): bool;

    /** Laravel connection config for the sandbox (cloned from the source plus overrides). */
    public function connectionConfig(SandboxRef $ref, array $sourceConfig): array;

    /** Remove seed rows, drop foreign keys (recording them in the plan) and disable triggers. */
    public function beforeCopy(SandboxRef $ref, Connection $demo, TablePlan $plan): void;

    public function copyChunk(SandboxRef $ref, Connection $source, Connection $demo, TableState $table, int $size): ChunkResult;

    /** Reset sequences, recreate foreign keys and re-enable triggers. */
    public function afterCopy(SandboxRef $ref, Connection $demo, TablePlan $plan): void;

    /** @return list<string> Referential problems; empty when the sandbox is consistent. */
    public function verify(SandboxRef $ref, Connection $demo, TablePlan $plan): array;

    /** Remove the container entirely. Idempotent. */
    public function drop(SandboxRef $ref): void;

    /** @return list<string> Containers this driver owns, for prune and orphan cleanup. */
    public function list(): array;
}
