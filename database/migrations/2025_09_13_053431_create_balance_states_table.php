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
        Schema::create('balance_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->onDelete('cascade');
            $table->foreignId('settlement_id')->nullable()->constrained('settlements')->onDelete('cascade');
            $table->json('user_balances'); // Store complete balance state for all users
            $table->timestamp('transaction_date');
            $table->timestamps();
            
            // Ensure only one of expense_id or settlement_id is set
            $table->index(['expense_id', 'settlement_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balance_states');
    }
};