<?php

namespace DemoMode\Tests\Fixtures;

use Livewire\Component;

class Counter extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<div>{{ $count }}</div>';
    }
}
