<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Like Telescope's migration: names its connection explicitly. In a sandbox build the name must
 * resolve to the sandbox instead of being blocked or writing to the source.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'source';
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('entries', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
        });
    }

    public function down(): void {}
};
