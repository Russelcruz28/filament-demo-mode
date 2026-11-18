<?php

namespace DemoMode\Metadata;

use DemoMode\Contracts\MetadataStore;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The demo_sandboxes table on the source connection, shared by every app server. Required when
 * sandboxes live on the database server (schema/database drivers) behind several app servers.
 */
class DatabaseMetadataStore implements MetadataStore
{
    public const TABLE = 'demo_sandboxes';

    public function current(): ?array
    {
        return $this->decode($this->connection()->table(self::TABLE)->where('status', 'current')->latest('updated_at')->value('state'));
    }

    public function setCurrent(array $saved): void
    {
        $this->connection()->transaction(function () use ($saved): void {
            $this->connection()->table(self::TABLE)->where('status', 'current')->where('token', '!=', $saved['token'])->delete();
            $this->put($saved['token'], 'current', $saved, $saved['ref'] ?? []);
        });
    }

    public function forgetCurrent(): void
    {
        $this->connection()->table(self::TABLE)->where('status', 'current')->delete();
    }

    public function provisioning(string $token): ?array
    {
        return $this->decode($this->connection()->table(self::TABLE)->where('token', $token)->where('status', 'provisioning')->value('state'));
    }

    public function saveProvisioning(string $token, array $state): void
    {
        $this->put($token, 'provisioning', $state, $state['ref'] ?? []);
    }

    public function forgetProvisioning(string $token): void
    {
        $this->connection()->table(self::TABLE)->where('token', $token)->where('status', 'provisioning')->delete();
    }

    private function put(string $token, string $status, array $state, array $ref): void
    {
        $now = now();
        $this->connection()->table(self::TABLE)->upsert([[
            'token' => $token, 'status' => $status, 'owner' => isset($state['owner']) ? (string) $state['owner'] : null,
            'driver' => $ref['driver'] ?? null, 'container' => $ref['container'] ?? null,
            'state' => json_encode($state, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
        ]], ['token'], ['status', 'owner', 'driver', 'container', 'state', 'updated_at']);
    }

    private function decode(?string $state): ?array
    {
        $data = $state === null ? null : json_decode($state, true);

        return is_array($data) ? $data : null;
    }

    private function connection(): Connection
    {
        return DB::connection(config('demo-mode.metadata_connection') ?: config('database.default'));
    }
}
