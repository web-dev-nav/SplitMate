<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing expenses to have user_count_at_time = 3 (assuming they were created with 3 users)
        DB::table('expenses')
            ->whereNull('user_count_at_time')
            ->update(['user_count_at_time' => 3]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration doesn't need to be reversed
    }
};