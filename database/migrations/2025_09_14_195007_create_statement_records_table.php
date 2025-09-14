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
        Schema::create('statement_records', function (Blueprint $table) {
            $table->id();

            // User this statement belongs to
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Transaction references (one will be null)
            $table->foreignId('expense_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('settlement_id')->nullable()->constrained()->onDelete('cascade');

            // Transaction details
            $table->string('transaction_type'); // 'expense', 'settlement'
            $table->string('description'); // Description of the transaction
            $table->decimal('amount', 10, 2); // Transaction amount (positive or negative)
            $table->string('reference_number')->unique(); // Unique transaction reference

            // Balance information
            $table->decimal('balance_before', 10, 2); // Balance before this transaction
            $table->decimal('balance_after', 10, 2); // Balance after this transaction
            $table->decimal('balance_change', 10, 2); // Net change from this transaction

            // Additional metadata
            $table->json('transaction_details')->nullable(); // Additional transaction data
            $table->datetime('transaction_date'); // When the transaction occurred
            $table->string('status')->default('completed'); // completed, pending, cancelled

            // Indexes for performance
            $table->index(['user_id', 'transaction_date']);
            $table->index(['transaction_type', 'transaction_date']);
            $table->index('reference_number');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statement_records');
    }
};
