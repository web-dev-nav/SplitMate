<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users');
            $table->foreignId('to_user_id')->constrained('users');
            $table->decimal('amount', 10, 2);
            $table->date('settlement_date');
            $table->string('payment_screenshot')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->onDelete('cascade');
            $table->foreignId('settlement_id')->nullable()->constrained('settlements')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('net_balance', 10, 2);
            $table->json('owes_details')->nullable();
            $table->json('receives_details')->nullable();
            $table->timestamp('snapshot_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_snapshots');
        Schema::dropIfExists('settlements');
    }
};
