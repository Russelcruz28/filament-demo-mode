<?php

namespace DemoMode\Tests;

use DemoMode\Sandbox\ConnectionPolicy;

class ConnectionPolicyTest extends TestCase
{
    public function test_connections_to_the_source_database_resolve_to_the_sandbox(): void
    {
        config(['database.connections' => [
            'pgsql' => ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'app'],
            'telescope' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'app'],
            'by_url' => ['driver' => 'pgsql', 'url' => 'postgresql://user:secret@127.0.0.1:5432/app'],
            'reporting' => ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'warehouse'],
            'legacy' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'app'],
            'reference' => ['driver' => 'pgsql', 'host' => 'other', 'database' => 'app'],
            'memory' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ], 'demo-mode.connections.allow' => ['reference']]);

        $this->assertSame([
            'pgsql' => 'sandbox', 'telescope' => 'sandbox', 'by_url' => 'sandbox', 'reporting' => 'block',
            'legacy' => 'block', 'reference' => 'allow', 'memory' => 'block',
        ], app(ConnectionPolicy::class)->resolve('pgsql'));
    }

    public function test_block_policy_only_honours_explicit_lists(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'demo-policy');
        config(['database.connections' => [
            'main' => ['driver' => 'sqlite', 'database' => $file],
            'alias' => ['driver' => 'sqlite', 'database' => $file],
            'forced' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]]);

        $this->assertSame(['main' => 'sandbox', 'alias' => 'sandbox', 'forced' => 'block'], app(ConnectionPolicy::class)->resolve('main'));
        config(['demo-mode.connections.policy' => 'block', 'demo-mode.connections.sandbox' => ['forced']]);
        $this->assertSame(['main' => 'block', 'alias' => 'block', 'forced' => 'sandbox'], app(ConnectionPolicy::class)->resolve('main'));
        unlink($file);
    }
}
