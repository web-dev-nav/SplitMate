<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Expense;
use App\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all existing expenses without participant_ids
        $expenses = Expense::whereNull('participant_ids')->get();
        
        foreach ($expenses as $expense) {
            // Get all active users at the time of expense creation
            $users = User::where('is_active', true)->get();
            
            // Use the user_count_at_time to determine how many users existed
            $userCount = $expense->user_count_at_time ?? $users->count();
            
            // Take the first N users (this is the fallback behavior)
            $participantIds = $users->take($userCount)->pluck('id')->toArray();
            
            $expense->update(['participant_ids' => $participantIds]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Set all participant_ids to null
        Expense::whereNotNull('participant_ids')->update(['participant_ids' => null]);
    }
};
