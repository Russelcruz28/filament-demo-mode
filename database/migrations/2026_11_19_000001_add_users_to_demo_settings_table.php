<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_settings', function (Blueprint $table) {
            // Users who always sign in to the saved demo.
            $table->json('users')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('demo_settings', function (Blueprint $table) {
            $table->dropColumn('users');
        });
    }
};
