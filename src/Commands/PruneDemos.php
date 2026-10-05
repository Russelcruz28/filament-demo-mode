<?php

namespace DemoMode\Commands;

use DemoMode\DemoManager;
use Illuminate\Console\Command;

class PruneDemos extends Command
{
    protected $signature = 'demo-mode:prune {--keep= : Retain this sandbox and delete all obsolete sandbox copies}';

    protected $description = 'Remove obsolete demo sandboxes without expiring the current demo';

    public function handle(): int
    {
        if ($token = $this->option('keep') ?: app(DemoManager::class)->currentToken()) {
            try {
                $count = app(DemoManager::class)->retainOnly($token);
            } catch (\InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
            $this->info("Removed {$count} obsolete demo sandboxes.");

            return self::SUCCESS;
        }
        $this->info('No current demo reference found. No sandboxes were removed.');

        return self::SUCCESS;
    }
}
