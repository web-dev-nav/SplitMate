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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->foreignId('paid_by_user_id')->constrained('users');
            $table->string('receipt_photo')->nullable();
            $table->date('expense_date');
            $table->boolean('is_payback')->default(false);
            $table->foreignId('payback_to_user_id')->nullable()->constrained('users');
            $table->decimal('payback_amount', 10, 2)->nullable();
            $table->integer('user_count_at_time')->nullable();
            $table->json('participant_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('expense_paybacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->onDelete('cascade');
            $table->foreignId('payback_to_user_id')->constrained('users');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_paybacks');
        Schema::dropIfExists('expenses');
    }
};
