<?php

namespace DemoMode\Sandbox;

/**
 * Copy progress for one table. Keys are the columns used for keyset paging (null = no usable unique key).
 */
final class TableState
{
    /**
     * @param  list<string>|null  $key
     * @param  list<string>|null  $columns  Columns copied into the sandbox; null until the sandbox schema is checked.
     * @param  list<mixed>|null  $last  The last copied key values.
     */
    public function __construct(
        public string $name,
        public ?array $key = null,
        public ?array $columns = null,
        public int $total = 0,
        public int $copied = 0,
        public ?array $last = null,
        public bool $done = false,
    ) {}

    public static function fromArray(array $table): self
    {
        return new self($table['name'], $table['key'] ?? null, $table['columns'] ?? null, (int) ($table['total'] ?? 0),
            (int) ($table['copied'] ?? 0), self::decode($table['last'] ?? null), (bool) ($table['done'] ?? false));
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'key' => $this->key, 'columns' => $this->columns, 'total' => $this->total,
            'copied' => $this->copied, 'last' => self::encode($this->last), 'done' => $this->done];
    }

    // Binary keys (e.g. MySQL BINARY(16) UUIDs) are not valid JSON strings.
    private static function encode(?array $values): ?array
    {
        return $values === null ? null : array_map(fn ($value) => is_string($value) && ! mb_check_encoding($value, 'UTF-8')
            ? ['base64' => base64_encode($value)] : $value, $values);
    }

    private static function decode(?array $values): ?array
    {
        return $values === null ? null : array_map(fn ($value) => is_array($value) && isset($value['base64'])
            ? base64_decode($value['base64']) : $value, $values);
    }
}
