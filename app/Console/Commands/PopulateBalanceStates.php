<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Expense;
use App\Models\Settlement;
use App\Models\BalanceState;
use App\Http\Controllers\ExpenseController;

class PopulateBalanceStates extends Command
{
    protected $signature = 'balance:populate';
    protected $description = 'Populate balance states for existing transactions';

    public function handle()
    {
        $this->info('Starting to populate balance states...');
        
        // Clear existing balance states
        BalanceState::truncate();
        $this->info('Cleared existing balance states.');
        
        // Get all expenses and settlements in chronological order
        $expenses = Expense::orderBy('created_at')->get();
        $settlements = Settlement::orderBy('created_at')->get();
        
        // Combine and sort all transactions
        $allTransactions = collect();
        
        foreach ($expenses as $expense) {
            $allTransactions->push([
                'type' => 'expense',
                'created_at' => $expense->created_at,
                'id' => $expense->id,
                'data' => $expense
            ]);
        }
        
        foreach ($settlements as $settlement) {
            $allTransactions->push([
                'type' => 'settlement',
                'created_at' => $settlement->created_at,
                'id' => $settlement->id,
                'data' => $settlement
            ]);
        }
        
        $allTransactions = $allTransactions->sortBy('created_at');
        
        $this->info("Found {$allTransactions->count()} transactions to process.");
        
        // Create a temporary controller instance to use its methods
        $controller = new ExpenseController();
        
        // Process each transaction and save balance state
        foreach ($allTransactions as $transaction) {
            if ($transaction['type'] === 'expense') {
                $expense = $transaction['data'];
                $this->info("Processing expense: {$expense->description} (ID: {$expense->id})");
                
                // Use reflection to call private method
                $reflection = new \ReflectionClass($controller);
                $method = $reflection->getMethod('saveBalanceState');
                $method->setAccessible(true);
                $method->invoke($controller, $expense->id, null);
                
            } else {
                $settlement = $transaction['data'];
                $this->info("Processing settlement: {$settlement->fromUser->name} paid {$settlement->toUser->name} \${$settlement->amount} (ID: {$settlement->id})");
                
                // Use reflection to call private method
                $reflection = new \ReflectionClass($controller);
                $method = $reflection->getMethod('saveBalanceState');
                $method->setAccessible(true);
                $method->invoke($controller, null, $settlement->id);
            }
        }
        
        $this->info('Balance states populated successfully!');
        $this->info('Total balance states created: ' . BalanceState::count());
    }
}