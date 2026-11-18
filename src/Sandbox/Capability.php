<?php

namespace DemoMode\Sandbox;

/**
 * Whether a driver or schema source can work here, and why not.
 */
final class Capability
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const UNSUPPORTED = 'unsupported';

    /** @param list<string> $reasons */
    private function __construct(public readonly string $status, public readonly array $reasons = []) {}

    public static function ok(): self
    {
        return new self(self::OK);
    }

    public static function warning(string ...$reasons): self
    {
        return new self(self::WARNING, $reasons);
    }

    public static function unsupported(string ...$reasons): self
    {
        return new self(self::UNSUPPORTED, $reasons);
    }

    public function supported(): bool
    {
        return $this->status !== self::UNSUPPORTED;
    }

    /** Combine two results; the worst status wins and all reasons are kept. */
    public function merge(self $other): self
    {
        $rank = [self::OK => 0, self::WARNING => 1, self::UNSUPPORTED => 2];

        return new self($rank[$other->status] > $rank[$this->status] ? $other->status : $this->status,
            [...$this->reasons, ...$other->reasons]);
    }
}
