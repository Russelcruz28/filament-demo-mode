<?php

namespace DemoMode;

use Illuminate\Support\Facades\Cache;

class SandboxRedis
{
    public function connection(?string $name = null): self
    {
        return $this;
    }

    public function get(string $key): mixed
    {
        return Cache::get('redis:'.$key);
    }

    public function incr(string $key): int
    {
        return Cache::lock('redis-lock:'.$key, 10)->block(5, function () use ($key): int {
            $value = (int) $this->get($key) + 1;
            Cache::forever('redis:'.$key, $value);

            return $value;
        });
    }

    public function __call(string $method, array $arguments): mixed
    {
        throw new \LogicException('Redis operation is unavailable in demo mode: '.$method);
    }
}
