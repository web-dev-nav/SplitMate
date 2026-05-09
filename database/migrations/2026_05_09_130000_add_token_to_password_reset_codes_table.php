<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_codes', function (Blueprint $table) {
            if (!Schema::hasColumn('password_reset_codes', 'token')) {
                $table->string('token', 80)->nullable()->unique()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_codes', function (Blueprint $table) {
            if (Schema::hasColumn('password_reset_codes', 'token')) {
                $table->dropUnique('password_reset_codes_token_unique');
                $table->dropColumn('token');
            }
        });
    }
};
