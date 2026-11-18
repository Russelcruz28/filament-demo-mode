<?php

namespace DemoMode\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class CreateWidget implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function __construct(public string $name) {}

    public function handle(): void
    {
        Widget::create(['name' => $this->name]);
    }
}
