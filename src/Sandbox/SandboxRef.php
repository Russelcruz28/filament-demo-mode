<?php

namespace DemoMode\Sandbox;

use Illuminate\Support\Str;

/**
 * Identifies one sandbox: its token, the driver that owns it and the driver's container.
 */
final class SandboxRef
{
    public function __construct(
        public readonly string $token,
        public readonly string $driver,
        public readonly string $container,
        public readonly ?string $owner = null,
        public readonly ?string $source = null,
    ) {
        if (! Str::isUuid($token)) {
            throw new \InvalidArgumentException('Invalid demo sandbox token.');
        }
    }

    public static function fromArray(array $ref): self
    {
        return new self((string) ($ref['token'] ?? ''), (string) $ref['driver'], (string) $ref['container'],
            isset($ref['owner']) ? (string) $ref['owner'] : null, $ref['source'] ?? null);
    }

    public function toArray(): array
    {
        return ['token' => $this->token, 'driver' => $this->driver, 'container' => $this->container,
            'owner' => $this->owner, 'source' => $this->source];
    }
}
