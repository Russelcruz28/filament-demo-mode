<?php

namespace DemoMode\Tests;

use DemoMode\DemoManager;
use DemoMode\DemoModeOptions;
use DemoMode\Facades\DemoMode;
use DemoMode\SandboxRuntime;
use DemoMode\Tests\Fixtures\CreateWidget;
use DemoMode\Tests\Fixtures\User;
use DemoMode\Tests\Fixtures\Widget;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class RuntimeOptionsTest extends TestCase
{
    private function startDemo(): string
    {
        $this->actingAs(User::create(['name' => 'Presenter']));
        app(DemoModeOptions::class)->authorize = fn () => true;
        app(DemoModeOptions::class)->prepare = fn () => null;
        app(DemoManager::class)->start();

        return session('demo_mode.token');
    }

    private function inDemo(\Closure $callback): mixed
    {
        $runtime = app(SandboxRuntime::class);
        $runtime->enter(session('demo_mode.token'));
        try {
            return $callback();
        } finally {
            $runtime->leave();
        }
    }

    public function test_sandbox_bindings_are_swapped_and_restored(): void
    {
        $this->startDemo();
        app()->instance('demo.test.push', $real = new \ArrayObject(['real']));
        config(['demo-mode.sandbox_bindings' => ['demo.test.push' => fn () => new \ArrayObject(['null'])]]);

        $this->inDemo(fn () => $this->assertSame(['null'], app('demo.test.push')->getArrayCopy()));

        $this->assertSame($real, app('demo.test.push'));
    }

    public function test_sync_queue_runs_jobs_inside_the_sandbox(): void
    {
        $this->startDemo();
        config(['demo-mode.runtime.queue' => 'sync', 'queue.default' => 'redis']);

        $this->inDemo(function (): void {
            CreateWidget::dispatch('from job');
            $this->assertSame(1, Widget::where('name', 'from job')->count());
        });

        $this->assertSame(0, Widget::where('name', 'from job')->count());
    }

    public function test_fake_queue_drops_jobs(): void
    {
        $this->startDemo();

        $this->inDemo(function (): void {
            CreateWidget::dispatch('dropped');
            Bus::assertDispatched(CreateWidget::class);
            $this->assertSame(0, Widget::count());
        });
    }

    public function test_prefix_cache_strategy_keeps_the_store_and_isolates_keys(): void
    {
        $token = $this->startDemo();
        config(['demo-mode.runtime.cache' => 'prefix', 'cache.prefix' => 'app_']);

        $this->inDemo(function () use ($token): void {
            $this->assertSame('array', config('cache.stores.demo.driver'));
            $this->assertSame('app_demo:'.$token.':', config('cache.stores.demo.prefix'));
        });
    }

    public function test_application_can_detect_demo_mode(): void
    {
        $this->startDemo();
        $template = '@demomode demo @else production @enddemomode';

        $this->assertFalse(DemoMode::active());
        $this->assertSame('production', trim(Blade::render($template)));
        $this->inDemo(function () use ($template): void {
            $this->assertTrue(DemoMode::active());
            $this->assertSame('demo', trim(Blade::render($template)));
        });
    }

    public function test_allowed_connections_pass_through_and_others_are_blocked(): void
    {
        $this->startDemo();
        config([
            'database.connections.reference' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'demo-mode.connections.allow' => ['reference'],
        ]);

        $this->inDemo(function (): void {
            $this->assertSame(1, (int) DB::connection('reference')->selectOne('select 1 as one')->one);
            $this->expectExceptionMessage('blocked access to a non-demo database connection [other]');
            DB::connection('other')->select('select 1');
        });
    }
}
