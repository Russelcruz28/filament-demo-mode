<?php

namespace DemoMode\Tests;

use DemoMode\Tests\Fixtures\Counter;
use Livewire\Livewire;

class LivewireCompatibilityTest extends TestCase
{
    public function test_livewire_preserves_the_demo_token_across_updates(): void
    {
        session(['demo_mode.token' => 'current-demo']);

        $component = Livewire::test(Counter::class);

        $this->assertSame('current-demo', $component->snapshot['memo']['demo_mode_token']);
        $component->call('increment')->assertSet('count', 1);
        $this->assertSame('current-demo', $component->snapshot['memo']['demo_mode_token']);
    }

    public function test_livewire_rejects_updates_after_demo_mode_changes(): void
    {
        $component = Livewire::test(Counter::class);
        session(['demo_mode.token' => 'new-demo']);

        $component->call('increment')->assertStatus(409);
    }

    public function test_livewire_accepts_updates_outside_demo_mode(): void
    {
        Livewire::test(Counter::class)->call('increment')->assertSet('count', 1);
    }
}
