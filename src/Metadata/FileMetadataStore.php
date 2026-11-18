<?php

namespace DemoMode\Metadata;

use DemoMode\Contracts\MetadataStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * current.json under the sandbox root and provisioning.json in each sandbox directory.
 * Suitable for a single app server.
 */
class FileMetadataStore implements MetadataStore
{
    public function current(): ?array
    {
        return $this->read(config('demo-mode.root').'/current.json');
    }

    public function setCurrent(array $saved): void
    {
        File::ensureDirectoryExists(config('demo-mode.root'), 0700);
        $this->write(config('demo-mode.root').'/current.json', $saved);
    }

    public function forgetCurrent(): void
    {
        File::delete(config('demo-mode.root').'/current.json');
    }

    public function provisioning(string $token): ?array
    {
        return $this->read($this->path($token));
    }

    public function saveProvisioning(string $token, array $state): void
    {
        File::ensureDirectoryExists(dirname($this->path($token)), 0700);
        $this->write($this->path($token), $state);
    }

    public function forgetProvisioning(string $token): void
    {
        File::delete($this->path($token));
    }

    private function path(string $token): string
    {
        if (! Str::isUuid($token)) {
            throw new \InvalidArgumentException('Invalid demo sandbox token.');
        }

        return config('demo-mode.root').'/'.$token.'/provisioning.json';
    }

    private function read(string $file): ?array
    {
        if (! File::exists($file)) {
            return null;
        }
        try {
            $data = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    private function write(string $file, array $data): void
    {
        File::replace($file, json_encode($data, JSON_THROW_ON_ERROR));
        File::chmod($file, 0600);
    }
}
