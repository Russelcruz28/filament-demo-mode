<?php

namespace DemoMode\Tests;

use DemoMode\DemoManager;
use DemoMode\DemoModeOptions;
use DemoMode\Tests\Fixtures\User;
use DemoMode\Tests\Fixtures\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ProvisioningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create(['name' => 'Presenter']));
        app(DemoModeOptions::class)->authorize = fn () => true;
        app(DemoModeOptions::class)->prepare = fn () => null;
        app(DemoModeOptions::class)->exit = fn () => '/production';
        config(['demo-mode.provisioning.chunk_size' => 2, 'demo-mode.provisioning.step_seconds' => 0]);
    }

    private function createWidgets(int $count): void
    {
        foreach (range(1, $count) as $number) {
            Widget::create(['name' => 'Widget '.$number]);
        }
    }

    private function demoWidgetNames(): array
    {
        $manager = app(DemoManager::class);
        $manager->configure(session('demo_mode.token'));

        return DB::connection('demo')->table('widgets')->orderBy('id')->pluck('name')->all();
    }

    public function test_start_copies_tables_larger_than_one_chunk(): void
    {
        $this->createWidgets(7);

        app(DemoManager::class)->start();

        $this->assertSame(Widget::orderBy('id')->pluck('name')->all(), $this->demoWidgetNames());
    }

    public function test_provisioning_reports_progress_across_short_steps(): void
    {
        $this->createWidgets(5);
        $manager = app(DemoManager::class);

        $manager->begin();
        $token = session('demo_mode_provisioning');
        $steps = [];
        do {
            $steps[] = $progress = $manager->advance();
        } while ($progress['status'] === 'running' && count($steps) < 50);

        $this->assertGreaterThan(3, count($steps));
        $percents = array_column($steps, 'percent');
        $this->assertSame($percents, array_values(collect($percents)->sort()->all()));
        $this->assertContains('Copying widgets (2 of 5 rows)', array_column($steps, 'message'));
        $this->assertSame('complete', $progress['status']);
        $this->assertSame(100, $progress['percent']);
        $this->assertSame(route('demo-mode.roles'), $progress['redirect']);
        $this->assertSame($token, session('demo_mode.token'));
        $this->assertFalse(session()->has('demo_mode_provisioning'));
        $this->assertFileDoesNotExist($manager->directory($token).'/provisioning.json');
        $this->assertCount(5, $this->demoWidgetNames());
    }

    public function test_cancel_discards_the_new_copy_and_keeps_the_saved_demo(): void
    {
        $this->createWidgets(3);
        $manager = app(DemoManager::class);
        $manager->start();
        $saved = session('demo_mode.token');
        $manager->stop();

        $manager->begin();
        $pending = session('demo_mode_provisioning');
        $manager->advance();
        $manager->cancel();

        $this->assertDirectoryDoesNotExist($manager->directory($pending));
        $this->assertFalse(session()->has('demo_mode_provisioning'));
        $this->assertSame($saved, $manager->currentToken());
        $this->assertTrue($manager->canRestore());
    }

    public function test_progress_routes_drive_provisioning_to_completion(): void
    {
        $this->createWidgets(3);
        app(DemoManager::class)->begin();

        $this->get(route('demo-mode.provisioning'))->assertRedirect();
        $response = null;
        for ($step = 0; $step < 50; $step++) {
            $response = $this->postJson(route('demo-mode.provisioning.step'))->assertOk();
            if ($response->json('status') !== 'running') {
                break;
            }
        }

        $response->assertJson(['status' => 'complete', 'redirect' => route('demo-mode.roles')]);
        $this->assertTrue(session()->has('demo_mode'));
    }

    public function test_progress_routes_without_pending_demo_are_rejected(): void
    {
        $this->postJson(route('demo-mode.provisioning.step'))->assertNotFound();
        $this->get(route('demo-mode.provisioning'))->assertRedirect();
    }

    public function test_cancel_route_discards_pending_demo(): void
    {
        app(DemoManager::class)->begin();
        $token = session('demo_mode_provisioning');

        $this->post(route('demo-mode.provisioning.cancel'))->assertRedirect();

        $this->assertDirectoryDoesNotExist(app(DemoManager::class)->directory($token));
    }

    public function test_failed_step_reports_a_message_and_discards_the_copy(): void
    {
        $this->createWidgets(2);
        $manager = app(DemoManager::class);
        $manager->begin();
        $token = session('demo_mode_provisioning');
        File::delete($manager->directory($token).'/provisioning.json');

        $this->postJson(route('demo-mode.provisioning.step'))
            ->assertStatus(422)
            ->assertJson(['status' => 'failed']);

        $this->assertDirectoryDoesNotExist($manager->directory($token));
        $this->assertFalse(session()->has('demo_mode_provisioning'));
    }
}
