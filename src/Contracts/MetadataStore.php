<?php

namespace DemoMode\Contracts;

/**
 * Where the saved sandbox reference and in-flight provisioning state live.
 */
interface MetadataStore
{
    /** @return array{token:string, owner:mixed, context:array, ref?:array}|null */
    public function current(): ?array;

    public function setCurrent(array $saved): void;

    public function forgetCurrent(): void;

    public function provisioning(string $token): ?array;

    public function saveProvisioning(string $token, array $state): void;

    public function forgetProvisioning(string $token): void;
}
