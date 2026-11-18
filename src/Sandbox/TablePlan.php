<?php

namespace DemoMode\Sandbox;

/**
 * The tables being copied plus driver state that must survive between progress requests
 * (e.g. dropped foreign key definitions). Drivers may write to $meta in their hooks.
 */
final class TablePlan
{
    /** @param list<TableState> $tables */
    public function __construct(public array $tables = [], public array $meta = []) {}

    public static function fromArray(array $plan): self
    {
        return new self(array_map(TableState::fromArray(...), $plan['tables'] ?? []), $plan['meta'] ?? []);
    }

    public function toArray(): array
    {
        return ['tables' => array_map(fn (TableState $table): array => $table->toArray(), $this->tables), 'meta' => $this->meta];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (TableState $table): string => $table->name, $this->tables);
    }
}
