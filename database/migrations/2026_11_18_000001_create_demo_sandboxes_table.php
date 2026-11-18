<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_sandboxes', function (Blueprint $table): void {
            $table->string('token', 36)->primary();
            $table->string('status', 20)->index();
            $table->string('owner')->nullable();
            $table->string('driver')->nullable();
            $table->string('container')->nullable();
            $table->longText('state');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_sandboxes');
    }
};
