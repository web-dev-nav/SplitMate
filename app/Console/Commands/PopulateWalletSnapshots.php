<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Expense;
use App\Models\Settlement;
use App\Models\User;
use App\Models\WalletSnapshot;

class PopulateWalletSnapshots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallet:populate-snapshots';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate wallet snapshots for all existing expenses and settlements';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to populate wallet snapshots...');
        
        // Clear existing snapshots
        WalletSnapshot::truncate();
        $this->info('Cleared existing wallet snapshots.');
        
        $users = User::where('is_active', true)->get();
        $this->info('Found ' . $users->count() . ' active users.');
        
        // Get all expenses and settlements in chronological order
        $expenses = Expense::orderBy('created_at')->get();
        $settlements = Settlement::orderBy('created_at')->get();
        
        $this->info('Found ' . $expenses->count() . ' expenses and ' . $settlements->count() . ' settlements.');
        
        // Process each expense
        foreach ($expenses as $expense) {
            $this->storeWalletSnapshotForExpense($expense, $users);
            $this->info('Processed expense: ' . $expense->description . ' - $' . $expense->amount);
        }
        
        // Process each settlement
        foreach ($settlements as $settlement) {
            $this->storeWalletSnapshotForSettlement($settlement, $users);
            $this->info('Processed settlement: $' . $settlement->amount . ' from ' . $settlement->fromUser->name . ' to ' . $settlement->toUser->name);
        }
        
        $this->info('Wallet snapshots populated successfully!');
        $this->info('Total snapshots created: ' . WalletSnapshot::count());
    }
    
    private function storeWalletSnapshotForExpense($expense, $users)
    {
        // Calculate balances up to this expense
        $balances = $this->calculateBalancesUpToExpense($expense, $users);
        
        foreach ($users as $user) {
            if (isset($balances[$user->id])) {
                $userBalance = $balances[$user->id];
                
                WalletSnapshot::create([
                    'expense_id' => $expense->id,
                    'settlement_id' => null,
                    'user_id' => $user->id,
                    'net_balance' => $this->calculateNetBalance($userBalance),
                    'owes_details' => $userBalance['owes'] ?? [],
                    'receives_details' => $userBalance['owed_by'] ?? [],
                    'snapshot_date' => $expense->created_at,
                ]);
            }
        }
    }
    
    private function storeWalletSnapshotForSettlement($settlement, $users)
    {
        // Calculate balances up to this settlement
        $balances = $this->calculateBalancesUpToSettlement($settlement, $users);
        
        foreach ($users as $user) {
            if (isset($balances[$user->id])) {
                $userBalance = $balances[$user->id];
                
                WalletSnapshot::create([
                    'expense_id' => null,
                    'settlement_id' => $settlement->id,
                    'user_id' => $user->id,
                    'net_balance' => $this->calculateNetBalance($userBalance),
                    'owes_details' => $userBalance['owes'] ?? [],
                    'receives_details' => $userBalance['owed_by'] ?? [],
                    'snapshot_date' => $settlement->created_at,
                ]);
            }
        }
    }
    
    private function calculateBalancesUpToExpense($currentExpense, $users)
    {
        // Get all expenses up to and including this one
        $expenses = Expense::where('created_at', '<=', $currentExpense->created_at)
            ->with('paybacks')
            ->orderBy('created_at')
            ->get();
        
        $settlements = Settlement::where('created_at', '<=', $currentExpense->created_at)
            ->orderBy('created_at')
            ->get();
        
        return $this->calculateBalancesFromTransactions($expenses, $settlements, $users);
    }
    
    private function calculateBalancesUpToSettlement($currentSettlement, $users)
    {
        // Get all expenses and settlements up to and including this one
        $expenses = Expense::where('created_at', '<=', $currentSettlement->created_at)
            ->with('paybacks')
            ->orderBy('created_at')
            ->get();
        
        $settlements = Settlement::where('created_at', '<=', $currentSettlement->created_at)
            ->orderBy('created_at')
            ->get();
        
        return $this->calculateBalancesFromTransactions($expenses, $settlements, $users);
    }
    
    private function calculateBalancesFromTransactions($expenses, $settlements, $users)
    {
        // Initialize net balances
        $netBalances = [];
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id != $user2->id) {
                    $netBalances[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process expenses
        foreach ($expenses as $expense) {
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $perPerson = $totalUsers > 0 ? $expense->amount / $totalUsers : $expense->amount;
            $paidBy = $expense->paid_by_user_id;
            $usersAtTime = $users->take($totalUsers);

            // Split expense among users FIRST
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // Auto-reduce debts for the payer
            $this->autoReduceDebtsForPayer($netBalances, $paidBy, $perPerson, $usersAtTime);
        }

        // Process settlements
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            $netBalances[$fromId][$toId] -= $amount;
            $netBalances[$toId][$fromId] += $amount;
        }

        // Convert to display format
        $balances = [];
        foreach ($users as $user) {
            $balances[$user->id] = [
                'name' => $user->name,
                'owes' => [],
                'owed_by' => [],
            ];

            foreach ($users as $otherUser) {
                if ($user->id != $otherUser->id) {
                    $netAmount = $netBalances[$user->id][$otherUser->id];
                    
                    if ($netAmount > 0) {
                        $balances[$user->id]['owes'][$otherUser->id] = $netAmount;
                    } elseif ($netAmount < 0) {
                        $balances[$user->id]['owed_by'][$otherUser->id] = abs($netAmount);
                    }
                }
            }
        }

        return $balances;
    }
    
    private function autoReduceDebtsForPayer(&$netBalances, $paidBy, $perPerson, $users)
    {
        // Find all debts the payer has to others
        $debtsToReduce = [];
        foreach ($users as $user) {
            if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                $debtsToReduce[$user->id] = $netBalances[$paidBy][$user->id];
            }
        }

        if (empty($debtsToReduce)) {
            return; // No debts to reduce
        }

        // Sort debts by amount (highest first)
        arsort($debtsToReduce);

        $remainingAmount = $perPerson;
        
        // Reduce each debt by the available amount from payer's share
        foreach ($debtsToReduce as $userId => $debtAmount) {
            if ($remainingAmount <= 0) break;
            
            $reductionAmount = min($debtAmount, $remainingAmount);
            
            // Reduce the debt: payer owes less to this user
            $netBalances[$paidBy][$userId] -= $reductionAmount;
            
            // The other user now owes the payer back the reduction amount
            $netBalances[$userId][$paidBy] += $reductionAmount;
            
            $remainingAmount -= $reductionAmount;
        }
    }
    
    private function calculateNetBalance($userBalance)
    {
        $netBalance = 0;
        
        // Subtract what user owes
        foreach ($userBalance['owes'] ?? [] as $amount) {
            $netBalance -= $amount;
        }
        
        // Add what user is owed
        foreach ($userBalance['owed_by'] ?? [] as $amount) {
            $netBalance += $amount;
        }
        
        return $netBalance;
    }
}
