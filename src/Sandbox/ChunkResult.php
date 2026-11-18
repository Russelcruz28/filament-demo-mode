<?php

namespace DemoMode\Sandbox;

final class ChunkResult
{
    /** @param list<mixed>|null $last */
    public function __construct(public readonly int $count, public readonly ?array $last, public readonly bool $done) {}
}
