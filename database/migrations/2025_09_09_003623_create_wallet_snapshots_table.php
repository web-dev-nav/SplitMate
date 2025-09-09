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
        Schema::create('wallet_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->decimal('net_balance', 10, 2);
            $table->json('owes_details'); // JSON array of who user owes and how much
            $table->json('receives_details'); // JSON array of who owes user and how much
            $table->timestamp('snapshot_date');
            $table->timestamps();
            
            $table->foreign('expense_id')->references('id')->on('expenses')->onDelete('cascade');
            $table->foreign('settlement_id')->references('id')->on('settlements')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->index(['expense_id', 'user_id']);
            $table->index(['settlement_id', 'user_id']);
            $table->index('snapshot_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_snapshots');
    }
};
