<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseControllerSimple extends Controller
{
    public function index()
    {
        $users = User::where('is_active', true)->get();
        $expenses = Expense::with('paidByUser')->latest()->paginate(5);
        $settlements = Settlement::with(['fromUser', 'toUser'])->latest()->paginate(5);
        
        // Calculate simple balances
        $balances = $this->calculateSimpleBalances();
        
        // Calculate simple expense details
        $expenseDetails = $this->calculateSimpleExpenseDetails($expenses);
        
        // Calculate simple settlement details
        $settlementDetails = $this->calculateSimpleSettlementDetails($settlements);
        
        return view('expenses.index', compact('users', 'expenses', 'settlements', 'balances', 'expenseDetails', 'settlementDetails'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'paid_by_user_id' => 'required|exists:users,id',
            'expense_date' => 'required|date',
            'receipt_photo' => 'required|image|max:2048',
        ]);

        $validated['receipt_photo'] = $request->file('receipt_photo')->store('receipts', 'public');
        
        // Set user count at time of expense
        $users = User::where('is_active', true)->get();
        $validated['user_count_at_time'] = $users->count();
        $validated['participant_ids'] = $users->pluck('id')->toArray();

        Expense::create($validated);

        return redirect()->back()->with('success', 'Expense added successfully!');
    }

    public function storeSettlement(Request $request)
    {
        $validated = $request->validate([
            'from_user_id' => 'required|exists:users,id',
            'to_user_id' => 'required|exists:users,id|different:from_user_id',
            'amount' => 'required|numeric|min:0.01',
            'settlement_date' => 'required|date',
            'payment_screenshot' => 'required|image|max:2048',
        ]);

        $validated['payment_screenshot'] = $request->file('payment_screenshot')->store('payment-screenshots', 'public');

        Settlement::create($validated);

        return redirect()->back()->with('success', 'Settlement recorded successfully!');
    }

    private function calculateSimpleBalances()
    {
        $users = User::where('is_active', true)->get();
        $balances = [];

        // Initialize balances
        foreach ($users as $user) {
            $balances[$user->id] = [
                'name' => $user->name,
                'owes' => [],
                'owed_by' => [],
            ];
        }

        // Process expenses
        $expenses = Expense::all();
        foreach ($expenses as $expense) {
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $perPerson = $expense->amount / $totalUsers;
            $paidBy = $expense->paid_by_user_id;

            // Get users who participated
            if ($expense->participant_ids) {
                $participantUsers = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                $participantUsers = $users->take($totalUsers);
            }

            // Each participant owes the payer (except payer themselves)
            foreach ($participantUsers as $user) {
                if ($user->id != $paidBy) {
                    if (!isset($balances[$user->id]['owes'][$paidBy])) {
                        $balances[$user->id]['owes'][$paidBy] = 0;
                    }
                    $balances[$user->id]['owes'][$paidBy] += $perPerson;
                }
            }
        }

        // Process settlements
        $settlements = Settlement::all();
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            // Reduce what fromUser owes toUser
            if (isset($balances[$fromId]['owes'][$toId])) {
                $currentDebt = $balances[$fromId]['owes'][$toId];
                if ($amount >= $currentDebt) {
                    // Payment covers all debt
                    unset($balances[$fromId]['owes'][$toId]);
                    $excess = $amount - $currentDebt;
                    if ($excess > 0) {
                        // toUser now owes fromUser the excess
                        if (!isset($balances[$toId]['owes'][$fromId])) {
                            $balances[$toId]['owes'][$fromId] = 0;
                        }
                        $balances[$toId]['owes'][$fromId] += $excess;
                    }
                } else {
                    // Partial payment
                    $balances[$fromId]['owes'][$toId] -= $amount;
                }
            } else {
                // No existing debt, toUser owes fromUser
                if (!isset($balances[$toId]['owes'][$fromId])) {
                    $balances[$toId]['owes'][$fromId] = 0;
                }
                $balances[$toId]['owes'][$fromId] += $amount;
            }
        }

        // Convert owes to owed_by for display
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id != $user2->id && isset($balances[$user1->id]['owes'][$user2->id])) {
                    $amount = $balances[$user1->id]['owes'][$user2->id];
                    $balances[$user2->id]['owed_by'][$user1->id] = $amount;
                }
            }
        }

        return $balances;
    }

    private function calculateSimpleExpenseDetails($expenses)
    {
        $users = User::where('is_active', true)->get();
        $expenseDetails = [];

        foreach ($expenses as $expense) {
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $perPerson = $expense->amount / $totalUsers;
            $paidBy = $expense->paid_by_user_id;

            // Get participants
            if ($expense->participant_ids) {
                $participantUsers = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                $participantUsers = $users->take($totalUsers);
            }

            $details = [
                'expense_id' => $expense->id,
                'description' => $expense->description,
                'amount' => $expense->amount,
                'per_person' => $perPerson,
                'paid_by' => $expense->paidByUser->name,
                'paid_by_id' => $paidBy,
                'expense_date' => $expense->expense_date,
                'normal_splits' => [],
                'debt_reductions' => [],
                'wallet_snapshot' => []
            ];

            // Normal splits
            foreach ($participantUsers as $user) {
                if ($user->id != $paidBy) {
                    $details['normal_splits'][] = [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'owes_amount' => $perPerson
                    ];
                }
            }

            // Simple debt reduction: payer's share reduces their debts
            $payerShare = $perPerson;
            $debtsBefore = $this->getDebtsBeforeExpense($expense);
            
            if (isset($debtsBefore[$paidBy]) && !empty($debtsBefore[$paidBy])) {
                // Sort debts highest first
                $payerDebts = $debtsBefore[$paidBy];
                arsort($payerDebts);
                
                $remainingReduction = $payerShare;
                foreach ($payerDebts as $userId => $debtAmount) {
                    if ($remainingReduction <= 0) break;
                    
                    $reductionAmount = min($remainingReduction, $debtAmount);
                    $user = $users->find($userId);
                    
                    $details['debt_reductions'][] = [
                        'user_id' => $userId,
                        'user_name' => $user->name,
                        'debt_before' => $debtAmount,
                        'reduction_amount' => $reductionAmount,
                        'debt_after' => $debtAmount - $reductionAmount
                    ];
                    
                    $remainingReduction -= $reductionAmount;
                }
            }

            // Wallet snapshot after this expense
            $details['wallet_snapshot'] = $this->getWalletSnapshotAfterExpense($expense);

            $expenseDetails[$expense->id] = $details;
        }

        return $expenseDetails;
    }

    private function calculateSimpleSettlementDetails($settlements)
    {
        $settlementDetails = [];

        foreach ($settlements as $settlement) {
            $debtBefore = $this->getDebtBeforeSettlement($settlement);
            $currentDebt = $debtBefore[$settlement->from_user_id][$settlement->to_user_id] ?? 0;
            $paymentAmount = $settlement->amount;
            
            $settlementDetails[$settlement->id] = [
                'settlement_id' => $settlement->id,
                'from_user_name' => $settlement->fromUser->name,
                'to_user_name' => $settlement->toUser->name,
                'payment_amount' => $paymentAmount,
                'current_debt' => $currentDebt,
                'reduction' => min($paymentAmount, $currentDebt),
                'remaining_debt' => max(0, $currentDebt - $paymentAmount),
                'excess_payment' => max(0, $paymentAmount - $currentDebt),
                'settlement_date' => $settlement->settlement_date,
            ];
        }

        return $settlementDetails;
    }

    private function getDebtsBeforeExpense($currentExpense)
    {
        $users = User::where('is_active', true)->get();
        $debts = [];

        // Initialize
        foreach ($users as $user) {
            $debts[$user->id] = [];
        }

        // Process expenses before this one
        $previousExpenses = Expense::where('created_at', '<', $currentExpense->created_at)->get();
        foreach ($previousExpenses as $expense) {
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $perPerson = $expense->amount / $totalUsers;
            $paidBy = $expense->paid_by_user_id;

            if ($expense->participant_ids) {
                $participantUsers = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                $participantUsers = $users->take($totalUsers);
            }

            foreach ($participantUsers as $user) {
                if ($user->id != $paidBy) {
                    if (!isset($debts[$user->id][$paidBy])) {
                        $debts[$user->id][$paidBy] = 0;
                    }
                    $debts[$user->id][$paidBy] += $perPerson;
                }
            }
        }

        // Process settlements before this expense
        $previousSettlements = Settlement::where('created_at', '<', $currentExpense->created_at)->get();
        foreach ($previousSettlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            if (isset($debts[$fromId][$toId])) {
                $debts[$fromId][$toId] = max(0, $debts[$fromId][$toId] - $amount);
                if ($debts[$fromId][$toId] == 0) {
                    unset($debts[$fromId][$toId]);
                }
            }
        }

        return $debts;
    }

    private function getDebtBeforeSettlement($currentSettlement)
    {
        $users = User::where('is_active', true)->get();
        $debts = [];

        // Initialize
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id != $user2->id) {
                    $debts[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process all expenses before this settlement
        $previousExpenses = Expense::where('created_at', '<', $currentSettlement->created_at)->get();
        foreach ($previousExpenses as $expense) {
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $perPerson = $expense->amount / $totalUsers;
            $paidBy = $expense->paid_by_user_id;

            if ($expense->participant_ids) {
                $participantUsers = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                $participantUsers = $users->take($totalUsers);
            }

            foreach ($participantUsers as $user) {
                if ($user->id != $paidBy) {
                    $debts[$user->id][$paidBy] += $perPerson;
                }
            }
        }

        // Process settlements before this one
        $previousSettlements = Settlement::where('created_at', '<', $currentSettlement->created_at)->get();
        foreach ($previousSettlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            if ($debts[$fromId][$toId] >= $amount) {
                $debts[$fromId][$toId] -= $amount;
            } else {
                $excess = $amount - $debts[$fromId][$toId];
                $debts[$fromId][$toId] = 0;
                $debts[$toId][$fromId] += $excess;
            }
        }

        return $debts;
    }

    private function getWalletSnapshotAfterExpense($expense)
    {
        $users = User::where('is_active', true)->get();
        $balances = [];

        // Initialize
        foreach ($users as $user) {
            $balances[$user->id] = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'owes' => [],
                'receives' => [],
                'net_balance' => 0
            ];
        }

        // Process all expenses up to this one
        $expenses = Expense::where('created_at', '<=', $expense->created_at)->get();
        foreach ($expenses as $exp) {
            $totalUsers = $exp->user_count_at_time ?? $users->count();
            $perPerson = $exp->amount / $totalUsers;
            $paidBy = $exp->paid_by_user_id;

            if ($exp->participant_ids) {
                $participantUsers = User::whereIn('id', $exp->participant_ids)->get();
            } else {
                $participantUsers = $users->take($totalUsers);
            }

            foreach ($participantUsers as $user) {
                if ($user->id != $paidBy) {
                    if (!isset($balances[$user->id]['owes'][$paidBy])) {
                        $balances[$user->id]['owes'][$paidBy] = 0;
                    }
                    $balances[$user->id]['owes'][$paidBy] += $perPerson;
                }
            }

            // Apply simple debt reduction for the payer
            if ($exp->id == $expense->id) { // Only for the current expense
                $payerShare = $perPerson;
                $remainingReduction = $payerShare;

                // Find payer's debts and reduce them
                foreach ($users as $user) {
                    if ($user->id != $paidBy && isset($balances[$paidBy]['owes'][$user->id]) && $remainingReduction > 0) {
                        $currentDebt = $balances[$paidBy]['owes'][$user->id];
                        $reductionAmount = min($remainingReduction, $currentDebt);
                        
                        $balances[$paidBy]['owes'][$user->id] -= $reductionAmount;
                        if ($balances[$paidBy]['owes'][$user->id] == 0) {
                            unset($balances[$paidBy]['owes'][$user->id]);
                        }
                        
                        $remainingReduction -= $reductionAmount;
                    }
                }
            }
        }

        // Process settlements up to this expense
        $settlements = Settlement::where('created_at', '<=', $expense->created_at)->get();
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            if (isset($balances[$fromId]['owes'][$toId])) {
                $currentDebt = $balances[$fromId]['owes'][$toId];
                if ($amount >= $currentDebt) {
                    unset($balances[$fromId]['owes'][$toId]);
                    $excess = $amount - $currentDebt;
                    if ($excess > 0) {
                        if (!isset($balances[$toId]['owes'][$fromId])) {
                            $balances[$toId]['owes'][$fromId] = 0;
                        }
                        $balances[$toId]['owes'][$fromId] += $excess;
                    }
                } else {
                    $balances[$fromId]['owes'][$toId] -= $amount;
                }
            } else {
                if (!isset($balances[$toId]['owes'][$fromId])) {
                    $balances[$toId]['owes'][$fromId] = 0;
                }
                $balances[$toId]['owes'][$fromId] += $amount;
            }
        }

        // Convert owes to receives and calculate net balance
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id != $user2->id && isset($balances[$user1->id]['owes'][$user2->id])) {
                    $amount = $balances[$user1->id]['owes'][$user2->id];
                    $balances[$user2->id]['receives'][$user1->id] = $amount;
                }
            }

            // Calculate net balance
            $netBalance = 0;
            foreach ($balances[$user1->id]['owes'] as $amount) {
                $netBalance -= $amount;
            }
            foreach ($balances[$user1->id]['receives'] as $amount) {
                $netBalance += $amount;
            }
            $balances[$user1->id]['net_balance'] = $netBalance;
        }

        return $balances;
    }
}
