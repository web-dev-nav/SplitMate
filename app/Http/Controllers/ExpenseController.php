<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpensePayback;
use App\Models\Settlement;
use App\Models\StatementRecord;
use App\Models\User;
use App\Models\BalanceState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    public function index()
    {
        $users = User::where('is_active', true)->get();
        $expenses = Expense::with(['paidByUser', 'paybackToUser', 'paybacks.paybackToUser'])->orderBy('created_at', 'desc')->paginate(5);
        $settlements = Settlement::with(['fromUser', 'toUser'])->orderBy('created_at', 'desc')->paginate(5);
        
        $balances = $this->calculateBalancesCorrectly();
        
        // Calculate debts for automatic payback suggestions
        $debts = $this->calculateDebtsForUser();
        
        // Calculate debt reduction details for each expense
        $expenseDetails = $this->calculateExpenseDetails($expenses, $users);
        
        // Calculate settlement breakdown details
        $settlementDetails = $this->calculateSettlementDetails($settlements, $users);

        return view('expenses.index', compact('users', 'expenses', 'settlements', 'balances', 'debts', 'expenseDetails', 'settlementDetails'));
    }

    public function store(Request $request)
    {
        try {
            // Debug: Log the request data
            \Log::info('Expense form data:', $request->all());
            
            $validated = $request->validate([
                'description' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0.01|max:999999.99',
                'paid_by_user_id' => 'required|exists:users,id',
                'expense_date' => 'required|date|before_or_equal:today',
                'receipt_photo' => 'required|image|max:2048',
            ], [
                'receipt_photo.required' => 'Please upload a receipt photo. This is required for expense tracking.',
                'receipt_photo.image' => 'The receipt must be an image file (JPG, PNG, GIF, etc.).',
                'receipt_photo.max' => 'The receipt image must be smaller than 2MB.',
                'amount.max' => 'The expense amount cannot exceed $999,999.99.',
                'expense_date.before_or_equal' => 'The expense date cannot be in the future.',
            ]);

            $validated['receipt_photo'] = $request->file('receipt_photo')->store('receipts', 'public');

            // Use database transaction to ensure data consistency
            $expense = DB::transaction(function () use ($validated) {
                // Set the user count and participant IDs at the time of expense creation
                $users = User::where('is_active', true)->get();
                
                // Validate minimum user count
                if ($users->count() < 2) {
                    throw new \InvalidArgumentException('At least 2 active users are required to create an expense.');
                }
                
                $validated['user_count_at_time'] = $users->count();
                $validated['participant_ids'] = $users->pluck('id')->toArray();

                // Create the expense
                $expense = Expense::create($validated);

                // Store wallet snapshot after expense
                $this->storeWalletSnapshot($users, $expense->id, null);

                // Save balance state after expense
                $this->saveBalanceState($expense->id, null);

                // Create individual statement records for all affected users
                $this->createStatementRecords($users, $expense);

                return $expense;
            });

            $message = 'Expense added successfully!';

            return redirect()->back()->with('success', $message);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['general' => $e->getMessage()])->withInput();
        } catch (\Exception $e) {
            \Log::error('Expense creation error: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Error adding expense: ' . $e->getMessage());
        }
    }

    public function storeSettlement(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_user_id' => 'required|exists:users,id',
                'to_user_id' => 'required|exists:users,id|different:from_user_id',
                'amount' => 'required|numeric|min:0.01',
                'settlement_date' => 'required|date',
                'payment_screenshot' => 'required|image|max:2048',
            ], [
                'payment_screenshot.required' => 'Please upload a payment screenshot. This is required to verify the settlement.',
                'payment_screenshot.image' => 'The payment proof must be an image file (JPG, PNG, GIF, etc.).',
                'payment_screenshot.max' => 'The payment screenshot must be smaller than 2MB.',
            ]);

            $validated['payment_screenshot'] = $request->file('payment_screenshot')->store('payment-screenshots', 'public');

            // Use database transaction to prevent race conditions
            $settlement = DB::transaction(function () use ($validated) {
                // Check if the payment amount exceeds what the user actually owes
                $balances = $this->calculateBalancesCorrectly();
                $fromUserId = $validated['from_user_id'];
                $toUserId = $validated['to_user_id'];
                $paymentAmount = $validated['amount'];

                // Get the current debt amount from the balances structure
                $currentDebt = 0;
                if (isset($balances[$fromUserId]['owes'][$toUserId])) {
                    $currentDebt = $balances[$fromUserId]['owes'][$toUserId];
                }

                // If the payment amount exceeds the debt, throw an exception (with 1 cent tolerance)
                if ($paymentAmount > $currentDebt + 0.01) {
                    throw new \InvalidArgumentException("You can only pay up to $" . number_format($currentDebt, 2) . " (the amount you currently owe).");
                }

                $settlement = Settlement::create($validated);

                // Store wallet snapshot after settlement
                $users = User::where('is_active', true)->get();
                $this->storeWalletSnapshot($users, null, $settlement->id);

                // Save balance state after settlement
                $this->saveBalanceState(null, $settlement->id);

                // Create individual statement records for all affected users
                $this->createStatementRecords($users, null, $settlement);

                return $settlement;
            });

            return redirect()->back()->with('success', 'Settlement recorded successfully!');
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['amount' => $e->getMessage()])->withInput();
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error recording settlement: ' . $e->getMessage());
        }
    }

    private function calculateBalances()
    {
        return $this->calculateBalancesCorrectly();
    }

    private function calculateDebtsForUser()
    {
        $balances = $this->calculateBalancesCorrectly();
        $debts = [];

        foreach ($balances as $userId => $userBalance) {
            $debts[$userId] = [
                'name' => $userBalance['name'],
                'owes' => []
            ];

            foreach ($userBalance['owes'] as $otherUserId => $amount) {
                $otherUser = User::find($otherUserId);
                $debts[$userId]['owes'][$otherUserId] = [
                    'name' => $otherUser->name,
                    'amount' => $amount
                ];
            }
        }

        return $debts;
    }

    private function calculateExpenseDetails($expenses, $users)
    {
        $expenseDetails = [];
        
        foreach ($expenses as $expense) {
            // Use the user count that existed when this expense was created
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $paidBy = $expense->paid_by_user_id;

            // Get only the users who existed when this expense was created
            if ($expense->participant_ids && count($expense->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                $usersAtTime = $users->take($totalUsers);
            }

            // Calculate precise per-person amounts with proper remainder distribution
            $expenseAmountCents = round($expense->amount * 100);
            $participantCount = count($usersAtTime);
            $perPersonCents = intval($expenseAmountCents / $participantCount);
            $remainderCents = $expenseAmountCents % $participantCount;

            $details = [
                'expense_id' => $expense->id,
                'description' => $expense->description,
                'amount' => $expense->amount,
                'per_person' => $perPersonCents / 100, // Base amount for display
                'paid_by' => $expense->paidByUser->name,
                'paid_by_id' => $paidBy,
                'expense_date' => $expense->expense_date,
                'debt_reductions' => [],
                'normal_splits' => [],
                'amount_others_owe_total' => 0
            ];

            // Calculate what debts existed before this expense
            $debtsBefore = $this->getDebtsBeforeExpense($expense, $users);

            // Calculate normal splits (who owes what to the payer) with precise amounts
            $userIndex = 0;
            $totalOthersOweCents = 0;
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    // Add extra cent to first users if there's a remainder
                    $thisPersonAmountCents = $perPersonCents + ($userIndex < $remainderCents ? 1 : 0);
                    $thisPersonAmount = $thisPersonAmountCents / 100;

                    $details['normal_splits'][] = [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'owes_amount' => $thisPersonAmount
                    ];

                    $totalOthersOweCents += $thisPersonAmountCents;
                }
                $userIndex++;
            }

            $details['amount_others_owe_total'] = $totalOthersOweCents / 100;
            
            // Calculate debt reductions for the payer
            if (isset($debtsBefore[$paidBy])) {
                // Sort debts by amount (highest first)
                $sortedDebts = $debtsBefore[$paidBy];
                arsort($sortedDebts);

                foreach ($sortedDebts as $userId => $debtAmount) {
                    // Only apply debt reduction if this user participated in the current expense
                    $userParticipated = $usersAtTime->contains('id', $userId);
                    if (!$userParticipated) continue;

                    // Find how much this specific user owes the payer from this expense
                    $userOwesPayer = 0;
                    foreach ($details['normal_splits'] as $split) {
                        if ($split['user_id'] == $userId) {
                            $userOwesPayer = $split['owes_amount'];
                            break;
                        }
                    }

                    // The reduction is the minimum of: debt amount OR what this user owes the payer
                    $reductionAmount = min($debtAmount, $userOwesPayer);

                    $user = $users->find($userId);
                    $details['debt_reductions'][] = [
                        'user_id' => $userId,
                        'user_name' => $user->name,
                        'debt_before' => $debtAmount,
                        'reduction_amount' => $reductionAmount,
                        'debt_after' => $debtAmount - $reductionAmount
                    ];
                }
            }
            
            // Get wallet balance snapshots before and after this transaction for bank statement style
            $details['wallet_snapshot_before'] = $this->getWalletSnapshotBeforeExpense($expense, $users);
            $details['wallet_snapshot_after'] = $this->getWalletSnapshotAfterSpecificExpense($expense, $users);
            
            $expenseDetails[$expense->id] = $details;
        }
        
        return $expenseDetails;
    }

    private function getDebtsBeforeExpense($currentExpense, $users)
    {
        // Get all expenses before this one (by created_at)
        $previousExpenses = Expense::where('created_at', '<', $currentExpense->created_at)
            ->with('paybacks')
            ->orderBy('created_at')
            ->get();
        
        $settlements = Settlement::where('created_at', '<', $currentExpense->created_at)
            ->orderBy('created_at')
            ->get();
        
        // Calculate balances up to this point
        $netBalances = [];
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id != $user2->id) {
                    $netBalances[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process previous expenses
        foreach ($previousExpenses as $expense) {
            // Use the user count that existed when this expense was created
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $perPerson = $totalUsers > 0 ? $expense->amount / $totalUsers : $expense->amount;
            $paidBy = $expense->paid_by_user_id;

            // Get only the users who existed when this expense was created
            if ($expense->participant_ids && count($expense->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                // Fallback for old expenses without participant_ids
                $usersAtTime = $users->take($totalUsers);
            }

            // Split expense among users who existed at the time FIRST
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // Apply debt reduction logic to get the actual debts that existed
            // Calculate expense amount in cents for precise reduction
            $expenseAmountCents = round($expense->amount * 100);
            $sortedUsers = $usersAtTime->sortBy('id');
            $this->autoReduceDebtsForPayer($netBalances, $paidBy, $expenseAmountCents, $sortedUsers);
        }

        // Process settlements with proper debt consolidation
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            // Check if there's an existing debt from 'from' to 'to'
            if ($netBalances[$fromId][$toId] > 0) {
                // Reduce existing debt first
                $reduction = min($amount, $netBalances[$fromId][$toId]);
                $netBalances[$fromId][$toId] -= $reduction;
                $netBalances[$toId][$fromId] += $reduction;
                
                // If payment exceeds debt, create reverse debt (to now owes from)
                if ($amount > $reduction) {
                    $excess = $amount - $reduction;
                    $netBalances[$toId][$fromId] -= $excess;
                    $netBalances[$fromId][$toId] += $excess;
                }
            } else {
                // No existing debt, create new debt (to owes from)
                $netBalances[$toId][$fromId] += $amount;
                $netBalances[$fromId][$toId] -= $amount;
            }
        }

        // Convert to debt format
        $debts = [];
        foreach ($users as $user) {
            $debts[$user->id] = [];
            foreach ($users as $otherUser) {
                if ($user->id != $otherUser->id && $netBalances[$user->id][$otherUser->id] > 0) {
                    $debts[$user->id][$otherUser->id] = $netBalances[$user->id][$otherUser->id];
                }
            }
        }

        return $debts;
    }

    private function autoReduceDebtsForPayer(&$netBalances, $paidBy, $expenseAmountCents, $usersAtTime)
    {
        // Find all debts the payer owes to others (positive values in netBalances[$paidBy][$otherUser])
        $debtsToReduce = [];
        foreach ($usersAtTime as $user) {
            if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                $debtsToReduce[$user->id] = $netBalances[$paidBy][$user->id];
            }
        }

        if (empty($debtsToReduce)) {
            return; // No debts to reduce
        }

        // Calculate precise per-person amounts with remainder distribution
        $participantCount = count($usersAtTime);
        $perPersonCents = intval($expenseAmountCents / $participantCount);
        $remainderCents = $expenseAmountCents % $participantCount;

        // Process debt reduction for each user with existing debt
        $userIndex = 0;
        foreach ($usersAtTime as $user) {
            if ($user->id != $paidBy && isset($debtsToReduce[$user->id])) {
                // Calculate how much this user owes the payer from this expense
                $thisPersonAmountCents = $perPersonCents + ($userIndex < $remainderCents ? 1 : 0);
                $thisPersonAmount = $thisPersonAmountCents / 100;

                $existingDebt = $debtsToReduce[$user->id];

                if ($thisPersonAmount >= $existingDebt) {
                    // The expense share is enough to completely pay off the debt + create new debt
                    $netBalances[$paidBy][$user->id] = 0; // Debt fully paid
                    $netBalances[$user->id][$paidBy] = $thisPersonAmount - $existingDebt; // User now owes the difference
                } else {
                    // The expense share only partially pays off the debt
                    $netBalances[$paidBy][$user->id] = $existingDebt - $thisPersonAmount; // Reduced debt
                    $netBalances[$user->id][$paidBy] = 0; // User owes nothing
                }
            }
            $userIndex++;
        }
    }

    private function storeWalletSnapshot($users, $expenseId = null, $settlementId = null)
    {
        // Calculate current wallet balances (this already includes debt reductions)
        $balances = $this->calculateBalancesCorrectly();

        foreach ($users as $user) {
            if (isset($balances[$user->id])) {
                $userBalance = $balances[$user->id];

                \App\Models\WalletSnapshot::create([
                    'expense_id' => $expenseId,
                    'settlement_id' => $settlementId,
                    'user_id' => $user->id,
                    'net_balance' => $this->calculateNetBalance($userBalance),
                    'owes_details' => $userBalance['owes'] ?? [],
                    'receives_details' => $userBalance['owed_by'] ?? [],
                    'snapshot_date' => now(),
                ]);
            }
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

    public function getWalletSnapshots()
    {
        $snapshots = \App\Models\WalletSnapshot::with(['user', 'expense', 'settlement'])
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy('snapshot_date');

        return response()->json($snapshots);
    }

    private function getWalletSnapshotFromDB($expenseId = null, $settlementId = null)
    {
        $query = \App\Models\WalletSnapshot::with('user');
        
        if ($expenseId) {
            $query->where('expense_id', $expenseId);
        }
        
        if ($settlementId) {
            $query->where('settlement_id', $settlementId);
        }
        
        $snapshots = $query->get();
        
        // Convert to the format expected by the view
        $walletBalances = [];
        foreach ($snapshots as $snapshot) {
            $walletBalances[$snapshot->user_id] = [
                'user_id' => $snapshot->user_id,
                'user_name' => $snapshot->user->name,
                'owes' => $snapshot->owes_details ?? [],
                'receives' => $snapshot->receives_details ?? [],
                'net_balance' => $snapshot->net_balance
            ];
        }
        
        return $walletBalances;
    }

    public function getDebtBeforeSettlement($settlement, $users)
    {
        // Get all expenses and settlements before this settlement (by created_at)
        $previousExpenses = Expense::where('created_at', '<', $settlement->created_at)
            ->with('paybacks')
            ->orderBy('created_at')
            ->get();
        
        $previousSettlements = Settlement::where('created_at', '<', $settlement->created_at)
            ->orderBy('created_at')
            ->get();
        
        // Calculate balances up to this point
        $netBalances = [];
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id != $user2->id) {
                    $netBalances[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process previous expenses
        foreach ($previousExpenses as $expense) {
            // Use the user count that existed when this expense was created
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $perPerson = $totalUsers > 0 ? $expense->amount / $totalUsers : $expense->amount;
            $paidBy = $expense->paid_by_user_id;

            // Get only the users who existed when this expense was created
            if ($expense->participant_ids && count($expense->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                // Fallback for old expenses without participant_ids
                $usersAtTime = $users->take($totalUsers);
            }

            // Split expense among users who existed at the time FIRST
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // Apply debt reduction logic to get the actual debts that existed
            // Calculate expense amount in cents for precise reduction
            $expenseAmountCents = round($expense->amount * 100);
            $sortedUsers = $usersAtTime->sortBy('id');
            $this->autoReduceDebtsForPayer($netBalances, $paidBy, $expenseAmountCents, $sortedUsers);
        }

        // Process previous settlements with proper debt consolidation
        foreach ($previousSettlements as $prevSettlement) {
            $fromId = $prevSettlement->from_user_id;
            $toId = $prevSettlement->to_user_id;
            $amount = $prevSettlement->amount;

            // Check if there's an existing debt from 'from' to 'to'
            if ($netBalances[$fromId][$toId] > 0) {
                // Reduce existing debt first
                $reduction = min($amount, $netBalances[$fromId][$toId]);
                $netBalances[$fromId][$toId] -= $reduction;
                $netBalances[$toId][$fromId] += $reduction;
                
                // If payment exceeds debt, create reverse debt (to now owes from)
                if ($amount > $reduction) {
                    $excess = $amount - $reduction;
                    $netBalances[$toId][$fromId] -= $excess;
                    $netBalances[$fromId][$toId] += $excess;
                }
            } else {
                // No existing debt, create new debt (to owes from)
                $netBalances[$toId][$fromId] += $amount;
                $netBalances[$fromId][$toId] -= $amount;
            }
        }

        return $netBalances;
    }

    private function calculateSettlementDetails($settlements, $users)
    {
        $settlementDetails = [];
        
        foreach ($settlements as $settlement) {
            // Get debt amounts before this settlement
            $debtBefore = $this->getDebtBeforeSettlement($settlement, $users);
            $currentDebt = $debtBefore[$settlement->from_user_id][$settlement->to_user_id] ?? 0;
            $paymentAmount = $settlement->amount;
            $reduction = min($paymentAmount, $currentDebt);
            $remainingDebt = max(0, $currentDebt - $paymentAmount);
            $excessPayment = max(0, $paymentAmount - $currentDebt);
            
            $settlementDetails[$settlement->id] = [
                'settlement_id' => $settlement->id,
                'from_user_name' => $settlement->fromUser->name,
                'to_user_name' => $settlement->toUser->name,
                'payment_amount' => $paymentAmount,
                'current_debt' => $currentDebt,
                'reduction' => $reduction,
                'remaining_debt' => $remainingDebt,
                'excess_payment' => $excessPayment,
                'settlement_date' => $settlement->settlement_date,
                'wallet_snapshot_before' => $this->getWalletSnapshotBeforeSettlement($settlement, $users),
                'wallet_snapshot_after' => $this->getWalletSnapshotAfterSettlement($settlement, $users),
            ];
        }
        
        return $settlementDetails;
    }

    /**
     * Calculate current balances using saved balance states for efficiency
     */
    private function calculateBalancesFromStates()
    {
        // Always use the correct calculation method instead of saved states
        return $this->calculateBalancesCorrectly();
    }

    /**
     * Save balance state after a transaction
     */
    private function saveBalanceState($expenseId = null, $settlementId = null)
    {
        // Calculate current balances
        $balances = $this->calculateBalancesCorrectly();

        // Save the balance state
        BalanceState::create([
            'expense_id' => $expenseId,
            'settlement_id' => $settlementId,
            'user_balances' => $balances,
            'transaction_date' => now(),
        ]);
    }

    /**
     * Get balance state before a specific transaction
     */
    private function getBalanceStateBefore($expenseId = null, $settlementId = null)
    {
        $query = BalanceState::query();
        
        if ($expenseId) {
            $expense = Expense::find($expenseId);
            if ($expense) {
                $query->where('transaction_date', '<', $expense->created_at);
            }
        }
        
        if ($settlementId) {
            $settlement = Settlement::find($settlementId);
            if ($settlement) {
                $query->where('transaction_date', '<', $settlement->created_at);
            }
        }
        
        $previousState = $query->latest('transaction_date')->first();
        
        return $previousState ? $previousState->user_balances : [];
    }

    /**
     * Get balance state after a specific transaction
     */
    private function getBalanceStateAfterTransaction($expenseId = null, $settlementId = null)
    {
        $query = BalanceState::query();
        
        if ($expenseId) {
            $query->where('expense_id', $expenseId);
        }
        
        if ($settlementId) {
            $query->where('settlement_id', $settlementId);
        }
        
        $state = $query->first();
        
        if (!$state) {
            return [];
        }
        
        // Convert balance state format to wallet snapshot format
        $walletBalances = [];
        foreach ($state->user_balances as $userId => $userBalance) {
            $walletBalances[$userId] = [
                'user_id' => $userId,
                'user_name' => $userBalance['name'],
                'owes' => $userBalance['owes'] ?? [],
                'receives' => $userBalance['owed_by'] ?? [],
                'net_balance' => $this->calculateNetBalance($userBalance)
            ];
        }
        
        return $walletBalances;
    }

    /**
     * Calculate balances using the proven correct logic
     */
    public function calculateBalancesCorrectly()
    {
        $users = User::where('is_active', true)->get();
        $expenses = Expense::with('paybacks')->orderBy('created_at')->get();
        $settlements = Settlement::orderBy('created_at')->get();
        
        // Initialize net balances between each pair of users
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
            // Use the user count that existed when this expense was created
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $paidBy = $expense->paid_by_user_id;

            // Get only the users who existed when this expense was created
            if ($expense->participant_ids && count($expense->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                // Fallback for old expenses without participant_ids
                $usersAtTime = $users->take($totalUsers);
            }

            // Calculate precise per-person amounts with proper remainder distribution
            $expenseAmountCents = round($expense->amount * 100);
            $participantCount = count($usersAtTime);
            $perPersonCents = intval($expenseAmountCents / $participantCount);
            $remainderCents = $expenseAmountCents % $participantCount;

            // Sort users by ID to ensure consistent remainder distribution
            $sortedUsers = $usersAtTime->sortBy('id');

            // Split expense among users who existed at the time (normal splitting) FIRST
            $userIndex = 0;
            $amountOthersOweCents = 0;
            foreach ($sortedUsers as $user) {
                if ($user->id != $paidBy) {
                    // Add extra cent to first users if there's a remainder
                    $thisPersonAmountCents = $perPersonCents + ($userIndex < $remainderCents ? 1 : 0);
                    $thisPersonAmount = $thisPersonAmountCents / 100;

                    // User owes the person who paid
                    $netBalances[$user->id][$paidBy] += $thisPersonAmount;
                    $netBalances[$paidBy][$user->id] -= $thisPersonAmount;

                    $amountOthersOweCents += $thisPersonAmountCents;
                }
                $userIndex++;
            }
            $amountOthersOwe = $amountOthersOweCents / 100;

            // Apply debt reduction if the payer has existing debts
            $hasExistingDebts = false;
            foreach ($sortedUsers as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }

            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $expenseAmountCents, $sortedUsers);
            }
        }

        // Process settlements with proper debt consolidation
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            // Check if there's an existing debt from 'from' to 'to'
            if ($netBalances[$fromId][$toId] > 0) {
                // Reduce existing debt first
                $reduction = min($amount, $netBalances[$fromId][$toId]);
                $netBalances[$fromId][$toId] -= $reduction;
                $netBalances[$toId][$fromId] += $reduction;
                
                // If payment exceeds debt, create reverse debt (to now owes from)
                if ($amount > $reduction) {
                    $excess = $amount - $reduction;
                    $netBalances[$toId][$fromId] -= $excess;
                    $netBalances[$fromId][$toId] += $excess;
                }
            } else {
                // No existing debt, create new debt (to owes from)
                $netBalances[$toId][$fromId] += $amount;
                $netBalances[$fromId][$toId] -= $amount;
            }
        }


        // Consolidate balances to prevent mutual debts
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id < $user2->id) { // Process each pair only once
                    $amount1to2 = $netBalances[$user1->id][$user2->id];
                    $amount2to1 = $netBalances[$user2->id][$user1->id];

                    if ($amount1to2 > 0 && $amount2to1 > 0) {
                        // Both owe each other - consolidate
                        if ($amount1to2 >= $amount2to1) {
                            $netBalances[$user1->id][$user2->id] = $amount1to2 - $amount2to1;
                            $netBalances[$user2->id][$user1->id] = 0;
                        } else {
                            $netBalances[$user2->id][$user1->id] = $amount2to1 - $amount1to2;
                            $netBalances[$user1->id][$user2->id] = 0;
                        }
                    }
                }
            }
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
                        // User owes money to otherUser
                        $balances[$user->id]['owes'][$otherUser->id] = $netAmount;
                    } elseif ($netAmount < 0) {
                        // OtherUser owes money to user
                        $balances[$user->id]['owed_by'][$otherUser->id] = abs($netAmount);
                    }
                }
            }
        }

        return $balances;
    }

    /**
     * Get correct wallet snapshot after a specific expense
     */
    private function getCorrectWalletSnapshotAfterExpense($expenseId)
    {
        $users = User::where('is_active', true)->get();
        $expenses = Expense::where('id', '<=', $expenseId)->orderBy('created_at')->get();
        $settlements = Settlement::where('created_at', '<=', Expense::find($expenseId)->created_at)->orderBy('created_at')->get();
        
        // Initialize net balances
        $netBalances = [];
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id != $user2->id) {
                    $netBalances[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process all expenses up to this point
        foreach ($expenses as $expense) {
            $totalUsers = $expense->user_count_at_time ?? $users->count();
            $perPerson = $totalUsers > 0 ? $expense->amount / $totalUsers : $expense->amount;
            $paidBy = $expense->paid_by_user_id;

            if ($expense->participant_ids && count($expense->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                $usersAtTime = $users->take($totalUsers);
            }

            // Split expense
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // Apply debt reduction if needed
            $expenseAmountCents = round($exp->amount * 100);
            $sortedUsers = $usersAtTime->sortBy('id');
            $hasExistingDebts = false;
            foreach ($sortedUsers as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }

            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $expenseAmountCents, $sortedUsers);
            }
        }

        // Process all settlements up to this point
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            if ($netBalances[$fromId][$toId] > 0) {
                $reduction = min($amount, $netBalances[$fromId][$toId]);
                $netBalances[$fromId][$toId] -= $reduction;
                $netBalances[$toId][$fromId] += $reduction;
                
                if ($amount > $reduction) {
                    $excess = $amount - $reduction;
                    $netBalances[$toId][$fromId] -= $excess;
                    $netBalances[$fromId][$toId] += $excess;
                }
            } else {
                $netBalances[$toId][$fromId] += $amount;
                $netBalances[$fromId][$toId] -= $amount;
            }
        }

        // Convert to wallet snapshot format
        $walletBalances = [];
        foreach ($users as $user) {
            $walletBalances[$user->id] = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'owes' => [],
                'receives' => [],
                'net_balance' => 0
            ];

            foreach ($users as $otherUser) {
                if ($user->id != $otherUser->id) {
                    $netAmount = $netBalances[$user->id][$otherUser->id];
                    
                    if ($netAmount > 0) {
                        $walletBalances[$user->id]['owes'][$otherUser->id] = $netAmount;
                    } elseif ($netAmount < 0) {
                        $walletBalances[$user->id]['receives'][$otherUser->id] = abs($netAmount);
                    }
                }
            }
            
            // Calculate net balance with proper precision
            $netBalance = 0;
            foreach ($walletBalances[$user->id]['owes'] as $amount) {
                $netBalance -= $amount;
            }
            foreach ($walletBalances[$user->id]['receives'] as $amount) {
                $netBalance += $amount;
            }
            $walletBalances[$user->id]['net_balance'] = round($netBalance, 2);
        }

        return $walletBalances;
    }

    private function getWalletSnapshotAfterSpecificExpense($expense, $users)
    {
        // Get all expenses up to and including this one
        $expenses = Expense::where('created_at', '<=', $expense->created_at)
            ->orderBy('created_at')
            ->get();

        // Get all settlements up to this expense
        $settlements = Settlement::where('created_at', '<=', $expense->created_at)
            ->orderBy('created_at')
            ->get();

        // Initialize net balances
        $netBalances = [];
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id != $user2->id) {
                    $netBalances[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process all expenses up to this point
        foreach ($expenses as $exp) {
            $totalUsers = $exp->user_count_at_time ?? $users->count();
            $perPerson = $totalUsers > 0 ? $exp->amount / $totalUsers : $exp->amount;
            $paidBy = $exp->paid_by_user_id;

            if ($exp->participant_ids && count($exp->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $exp->participant_ids)->get();
            } else {
                $usersAtTime = $users->take($totalUsers);
            }

            // Split expense
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // Apply debt reduction if needed
            $expenseAmountCents = round($exp->amount * 100);
            $sortedUsers = $usersAtTime->sortBy('id');
            $hasExistingDebts = false;
            foreach ($sortedUsers as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }

            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $expenseAmountCents, $sortedUsers);
            }
        }

        // Process all settlements up to this point
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            if ($netBalances[$fromId][$toId] > 0) {
                $reduction = min($amount, $netBalances[$fromId][$toId]);
                $netBalances[$fromId][$toId] -= $reduction;
                $netBalances[$toId][$fromId] += $reduction;

                if ($amount > $reduction) {
                    $excess = $amount - $reduction;
                    $netBalances[$toId][$fromId] -= $excess;
                    $netBalances[$fromId][$toId] += $excess;
                }
            } else {
                $netBalances[$toId][$fromId] += $amount;
                $netBalances[$fromId][$toId] -= $amount;
            }
        }

        // Consolidate balances to prevent mutual debts
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id < $user2->id) { // Process each pair only once
                    $amount1to2 = $netBalances[$user1->id][$user2->id];
                    $amount2to1 = $netBalances[$user2->id][$user1->id];

                    if ($amount1to2 > 0 && $amount2to1 > 0) {
                        // Both owe each other - consolidate
                        if ($amount1to2 >= $amount2to1) {
                            $netBalances[$user1->id][$user2->id] = $amount1to2 - $amount2to1;
                            $netBalances[$user2->id][$user1->id] = 0;
                        } else {
                            $netBalances[$user2->id][$user1->id] = $amount2to1 - $amount1to2;
                            $netBalances[$user1->id][$user2->id] = 0;
                        }
                    }
                }
            }
        }

        // Convert to wallet snapshot format
        $walletBalances = [];
        foreach ($users as $user) {
            $walletBalances[$user->id] = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'owes' => [],
                'receives' => [],
                'net_balance' => 0
            ];

            foreach ($users as $otherUser) {
                if ($user->id != $otherUser->id) {
                    $netAmount = $netBalances[$user->id][$otherUser->id];

                    if ($netAmount > 0) {
                        $walletBalances[$user->id]['owes'][$otherUser->id] = $netAmount;
                    } elseif ($netAmount < 0) {
                        $walletBalances[$user->id]['receives'][$otherUser->id] = abs($netAmount);
                    }
                }
            }

            // Calculate net balance with proper precision
            $netBalance = 0;
            foreach ($walletBalances[$user->id]['owes'] as $amount) {
                $netBalance -= $amount;
            }
            foreach ($walletBalances[$user->id]['receives'] as $amount) {
                $netBalance += $amount;
            }
            $walletBalances[$user->id]['net_balance'] = round($netBalance, 2);
        }

        return $walletBalances;
    }

    /**
     * Get wallet snapshot BEFORE a specific expense for bank statement-style running balance
     */
    private function getWalletSnapshotBeforeExpense($expense, $users)
    {
        // Get all expenses BEFORE this one
        $expenses = Expense::where('created_at', '<', $expense->created_at)
            ->orderBy('created_at')
            ->get();

        // Get all settlements BEFORE this expense
        $settlements = Settlement::where('created_at', '<', $expense->created_at)
            ->orderBy('created_at')
            ->get();

        // Initialize net balances
        $netBalances = [];
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id != $user2->id) {
                    $netBalances[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process all expenses up to (but not including) this point
        foreach ($expenses as $exp) {
            $totalUsers = $exp->user_count_at_time ?? $users->count();
            $paidBy = $exp->paid_by_user_id;

            if ($exp->participant_ids && count($exp->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $exp->participant_ids)->get();
            } else {
                $usersAtTime = $users->take($totalUsers);
            }

            // Calculate precise per-person amounts with proper remainder distribution
            $expenseAmountCents = round($exp->amount * 100);
            $participantCount = count($usersAtTime);
            $perPersonCents = intval($expenseAmountCents / $participantCount);
            $remainderCents = $expenseAmountCents % $participantCount;

            // Split expense among users
            $userIndex = 0;
            $amountOthersOweCents = 0;
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    $thisPersonAmountCents = $perPersonCents + ($userIndex < $remainderCents ? 1 : 0);
                    $thisPersonAmount = $thisPersonAmountCents / 100;

                    $netBalances[$user->id][$paidBy] += $thisPersonAmount;
                    $netBalances[$paidBy][$user->id] -= $thisPersonAmount;

                    $amountOthersOweCents += $thisPersonAmountCents;
                }
                $userIndex++;
            }

            // Apply debt reduction if needed
            $sortedUsers = $usersAtTime->sortBy('id');
            $hasExistingDebts = false;
            foreach ($sortedUsers as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }

            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $expenseAmountCents, $sortedUsers);
            }
        }

        // Process all settlements up to this point
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            if ($netBalances[$fromId][$toId] > 0) {
                $reduction = min($amount, $netBalances[$fromId][$toId]);
                $netBalances[$fromId][$toId] -= $reduction;
                $netBalances[$toId][$fromId] += $reduction;

                if ($amount > $reduction) {
                    $excess = $amount - $reduction;
                    $netBalances[$toId][$fromId] -= $excess;
                    $netBalances[$fromId][$toId] += $excess;
                }
            } else {
                $netBalances[$toId][$fromId] += $amount;
                $netBalances[$fromId][$toId] -= $amount;
            }
        }

        // Convert to wallet snapshot format
        $walletBalances = [];
        foreach ($users as $user) {
            $walletBalances[$user->id] = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'owes' => [],
                'receives' => [],
                'net_balance' => 0
            ];

            foreach ($users as $otherUser) {
                if ($user->id != $otherUser->id) {
                    $netAmount = $netBalances[$user->id][$otherUser->id];

                    if ($netAmount > 0) {
                        $walletBalances[$user->id]['owes'][$otherUser->id] = $netAmount;
                    } elseif ($netAmount < 0) {
                        $walletBalances[$user->id]['receives'][$otherUser->id] = abs($netAmount);
                    }
                }
            }

            // Calculate net balance with proper precision
            $netBalance = 0;
            foreach ($walletBalances[$user->id]['owes'] as $amount) {
                $netBalance -= $amount;
            }
            foreach ($walletBalances[$user->id]['receives'] as $amount) {
                $netBalance += $amount;
            }
            $walletBalances[$user->id]['net_balance'] = round($netBalance, 2);
        }

        return $walletBalances;
    }

    /**
     * Get wallet snapshot BEFORE a specific settlement
     */
    private function getWalletSnapshotBeforeSettlement($settlement, $users)
    {
        // Get all expenses before this settlement
        $expenses = Expense::where('created_at', '<', $settlement->created_at)
            ->orderBy('created_at')
            ->get();

        // Get all settlements before this settlement
        $settlements = Settlement::where('created_at', '<', $settlement->created_at)
            ->orderBy('created_at')
            ->get();

        // Use the same calculation logic as getWalletSnapshotBeforeExpense
        return $this->calculateWalletBalancesUpToPoint($expenses, $settlements, $users);
    }

    /**
     * Get wallet snapshot AFTER a specific settlement
     */
    private function getWalletSnapshotAfterSettlement($settlement, $users)
    {
        // Get all expenses up to and before this settlement
        $expenses = Expense::where('created_at', '<=', $settlement->created_at)
            ->orderBy('created_at')
            ->get();

        // Get all settlements up to and including this settlement
        $settlements = Settlement::where('created_at', '<=', $settlement->created_at)
            ->orderBy('created_at')
            ->get();

        return $this->calculateWalletBalancesUpToPoint($expenses, $settlements, $users);
    }

    /**
     * Helper method to calculate wallet balances up to a specific point in time
     */
    private function calculateWalletBalancesUpToPoint($expenses, $settlements, $users)
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

        // Process all expenses
        foreach ($expenses as $exp) {
            $totalUsers = $exp->user_count_at_time ?? $users->count();
            $paidBy = $exp->paid_by_user_id;

            if ($exp->participant_ids && count($exp->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $exp->participant_ids)->get();
            } else {
                $usersAtTime = $users->take($totalUsers);
            }

            // Calculate precise per-person amounts with proper remainder distribution
            $expenseAmountCents = round($exp->amount * 100);
            $participantCount = count($usersAtTime);
            $perPersonCents = intval($expenseAmountCents / $participantCount);
            $remainderCents = $expenseAmountCents % $participantCount;

            // Split expense among users
            $userIndex = 0;
            $amountOthersOweCents = 0;
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    $thisPersonAmountCents = $perPersonCents + ($userIndex < $remainderCents ? 1 : 0);
                    $thisPersonAmount = $thisPersonAmountCents / 100;

                    $netBalances[$user->id][$paidBy] += $thisPersonAmount;
                    $netBalances[$paidBy][$user->id] -= $thisPersonAmount;

                    $amountOthersOweCents += $thisPersonAmountCents;
                }
                $userIndex++;
            }

            // Apply debt reduction if needed
            $sortedUsers = $usersAtTime->sortBy('id');
            $hasExistingDebts = false;
            foreach ($sortedUsers as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }

            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $expenseAmountCents, $sortedUsers);
            }
        }

        // Process all settlements
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            if ($netBalances[$fromId][$toId] > 0) {
                $reduction = min($amount, $netBalances[$fromId][$toId]);
                $netBalances[$fromId][$toId] -= $reduction;
                $netBalances[$toId][$fromId] += $reduction;

                if ($amount > $reduction) {
                    $excess = $amount - $reduction;
                    $netBalances[$toId][$fromId] -= $excess;
                    $netBalances[$fromId][$toId] += $excess;
                }
            } else {
                $netBalances[$toId][$fromId] += $amount;
                $netBalances[$fromId][$toId] -= $amount;
            }
        }

        // Consolidate balances to prevent mutual debts
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id < $user2->id) { // Process each pair only once
                    $amount1to2 = $netBalances[$user1->id][$user2->id];
                    $amount2to1 = $netBalances[$user2->id][$user1->id];

                    if ($amount1to2 > 0 && $amount2to1 > 0) {
                        // Both owe each other - consolidate
                        if ($amount1to2 >= $amount2to1) {
                            $netBalances[$user1->id][$user2->id] = $amount1to2 - $amount2to1;
                            $netBalances[$user2->id][$user1->id] = 0;
                        } else {
                            $netBalances[$user2->id][$user1->id] = $amount2to1 - $amount1to2;
                            $netBalances[$user1->id][$user2->id] = 0;
                        }
                    }
                }
            }
        }

        // Convert to wallet snapshot format
        $walletBalances = [];
        foreach ($users as $user) {
            $walletBalances[$user->id] = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'owes' => [],
                'receives' => [],
                'net_balance' => 0
            ];

            foreach ($users as $otherUser) {
                if ($user->id != $otherUser->id) {
                    $netAmount = $netBalances[$user->id][$otherUser->id];

                    if ($netAmount > 0) {
                        $walletBalances[$user->id]['owes'][$otherUser->id] = $netAmount;
                    } elseif ($netAmount < 0) {
                        $walletBalances[$user->id]['receives'][$otherUser->id] = abs($netAmount);
                    }
                }
            }

            // Calculate net balance with proper precision
            $netBalance = 0;
            foreach ($walletBalances[$user->id]['owes'] as $amount) {
                $netBalance -= $amount;
            }
            foreach ($walletBalances[$user->id]['receives'] as $amount) {
                $netBalance += $amount;
            }
            $walletBalances[$user->id]['net_balance'] = round($netBalance, 2);
        }

        return $walletBalances;
    }

    /**
     * Get statement history for a specific user (bank statement view)
     */
    public function getUserStatementHistory($userId, $limit = 50)
    {
        return StatementRecord::with(['expense', 'settlement'])
            ->forUser($userId)
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get statement history for all users (admin view)
     */
    public function getAllStatementHistory($limit = 100)
    {
        return StatementRecord::with(['user', 'expense', 'settlement'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get statement history between dates
     */
    public function getStatementHistoryBetweenDates($userId = null, $startDate = null, $endDate = null)
    {
        $query = StatementRecord::with(['user', 'expense', 'settlement']);

        if ($userId) {
            $query->forUser($userId);
        }

        if ($startDate && $endDate) {
            $query->betweenDates($startDate, $endDate);
        }

        return $query->orderBy('transaction_date', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->get();
    }

    /**
     * Web view for user's statement history
     */
    public function userStatementView($userId)
    {
        $user = User::find($userId);
        if (!$user) {
            abort(404, 'User not found');
        }

        // Get all users for navigation
        $allUsers = User::where('is_active', true)->get();

        $statements = $this->getUserStatementHistory($userId, 100);
        $currentBalance = $statements->first()?->balance_after ?? 0;

        return view('statements.user', compact('user', 'allUsers', 'statements', 'currentBalance'));
    }

    /**
     * API endpoint to get user's statement history
     */
    public function apiStatementHistory(Request $request, $userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $limit = $request->get('limit', 50);
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        if ($startDate && $endDate) {
            $statements = $this->getStatementHistoryBetweenDates($userId, $startDate, $endDate);
        } else {
            $statements = $this->getUserStatementHistory($userId, $limit);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'statements' => $statements->map(function ($record) {
                return [
                    'id' => $record->id,
                    'reference_number' => $record->reference_number,
                    'transaction_date' => $record->transaction_date->format('Y-m-d H:i:s'),
                    'transaction_type' => $record->transaction_type,
                    'description' => $record->description,
                    'amount' => $record->amount,
                    'balance_before' => $record->balance_before,
                    'balance_after' => $record->balance_after,
                    'balance_change' => $record->balance_change,
                    'formatted_balance_change' => $record->formatted_balance_change,
                    'formatted_balance_after' => $record->formatted_balance_after,
                    'status' => $record->status,
                ];
            }),
            'summary' => [
                'total_records' => $statements->count(),
                'current_balance' => $statements->first()?->balance_after ?? 0,
                'date_range' => [
                    'start' => $statements->last()?->transaction_date?->format('Y-m-d') ?? null,
                    'end' => $statements->first()?->transaction_date?->format('Y-m-d') ?? null,
                ]
            ]
        ]);
    }

    /**
     * Debug balance calculation for a specific user and transaction
     */
    public function debugBalance($userId = null)
    {
        $users = User::where('is_active', true)->get();
        $expenses = Expense::orderBy('created_at')->get();

        if (!$userId) {
            $userId = $users->first()->id;
        }

        $debugInfo = [];

        foreach ($expenses as $expense) {
            $balancesBefore = $this->getWalletSnapshotBeforeExpense($expense, $users);
            $balancesAfter = $this->getWalletSnapshotAfterSpecificExpense($expense, $users);

            $debugInfo[] = [
                'expense' => [
                    'id' => $expense->id,
                    'description' => $expense->description,
                    'amount' => $expense->amount,
                    'paid_by' => $expense->paid_by_user_id,
                    'created_at' => $expense->created_at->format('Y-m-d H:i:s')
                ],
                'user_' . $userId . '_before' => $balancesBefore[$userId] ?? [],
                'user_' . $userId . '_after' => $balancesAfter[$userId] ?? []
            ];
        }

        return response()->json($debugInfo);
    }

    /**
     * Regenerate all statement records from existing transactions
     */
    public function regenerateStatementRecords()
    {
        // Clear existing statement records
        StatementRecord::truncate();

        $users = User::where('is_active', true)->get();

        // Get all expenses and settlements
        $expenses = Expense::orderBy('created_at')->get();
        $settlements = Settlement::orderBy('created_at')->get();

        // Regenerate for all expenses
        foreach ($expenses as $expense) {
            $this->createStatementRecords($users, $expense, null);
        }

        // Regenerate for all settlements
        foreach ($settlements as $settlement) {
            $this->createStatementRecords($users, null, $settlement);
        }

        return response()->json(['message' => 'Statement records regenerated successfully']);
    }

    /**
     * Calculate debt reductions that occurred in this transaction
     */
    private function calculateDebtReductions($userId, $balancesBefore, $balancesAfter, $users)
    {
        $debtReductions = [];

        foreach ($users as $otherUser) {
            if ($otherUser->id == $userId) continue;

            $owesBefore = $balancesBefore[$userId]['owes'][$otherUser->id] ?? 0;
            $owesAfter = $balancesAfter[$userId]['owes'][$otherUser->id] ?? 0;
            $receivesBefore = $balancesBefore[$userId]['receives'][$otherUser->id] ?? 0;
            $receivesAfter = $balancesAfter[$userId]['receives'][$otherUser->id] ?? 0;

            // Check if debt was reduced (user owed less after)
            if ($owesBefore > $owesAfter && ($owesBefore - $owesAfter) >= 0.01) {
                $reduction = $owesBefore - $owesAfter;
                $debtReductions[] = [
                    'type' => 'debt_reduced',
                    'other_user_id' => $otherUser->id,
                    'other_user_name' => $otherUser->name,
                    'amount' => $reduction,
                    'before' => $owesBefore,
                    'after' => $owesAfter
                ];
            }

            // Check if receivable was reduced (user receives less after)
            if ($receivesBefore > $receivesAfter && ($receivesBefore - $receivesAfter) >= 0.01) {
                $reduction = $receivesBefore - $receivesAfter;
                $debtReductions[] = [
                    'type' => 'receivable_reduced',
                    'other_user_id' => $otherUser->id,
                    'other_user_name' => $otherUser->name,
                    'amount' => $reduction,
                    'before' => $receivesBefore,
                    'after' => $receivesAfter
                ];
            }
        }

        return $debtReductions;
    }

    /**
     * Create individual statement records for each user affected by a transaction
     */
    private function createStatementRecords($users, $expense = null, $settlement = null)
    {
        $balancesBefore = null;
        $balancesAfter = null;

        if ($expense) {
            // For expenses, get balances before and after this specific expense
            $balancesBefore = $this->getWalletSnapshotBeforeExpense($expense, $users);
            $balancesAfter = $this->getWalletSnapshotAfterSpecificExpense($expense, $users);
        } elseif ($settlement) {
            // For settlements, get balances before and after this specific settlement
            $balancesBefore = $this->getWalletSnapshotBeforeSettlement($settlement, $users);
            $balancesAfter = $this->getWalletSnapshotAfterSettlement($settlement, $users);
        }

        foreach ($users as $user) {
            $balanceBefore = round($balancesBefore[$user->id]['net_balance'] ?? 0, 2);
            $balanceAfter = round($balancesAfter[$user->id]['net_balance'] ?? 0, 2);
            $balanceChange = round($balanceAfter - $balanceBefore, 2);

            // Only create records for users whose balance was affected
            if (abs($balanceChange) >= 0.01) { // At least 1 cent change
                $transactionType = $expense ? 'expense' : 'settlement';
                $description = '';
                $amount = 0;

                if ($expense) {
                    $description = "Expense: {$expense->description}";
                    if ($expense->paid_by_user_id == $user->id) {
                        $amount = $expense->amount; // Positive for payer
                        $description .= " (Paid by you)";
                    } else {
                        $amount = -($expense->amount / ($expense->user_count_at_time ?? $users->count())); // Negative for others
                        $description .= " (Your share)";
                    }
                } elseif ($settlement) {
                    $description = "Payment: {$settlement->fromUser->name} → {$settlement->toUser->name}";
                    if ($settlement->from_user_id == $user->id) {
                        $amount = -$settlement->amount; // Negative for payer
                        $description = "Payment made to {$settlement->toUser->name}";
                        // For payer, show cash outflow regardless of net balance improvement
                        $balanceChange = -$settlement->amount;
                    } elseif ($settlement->to_user_id == $user->id) {
                        $amount = $settlement->amount; // Positive for receiver
                        $description = "Payment received from {$settlement->fromUser->name}";
                        // For receiver, the balance change should be positive (cash received)
                        // even if the net balance calculation shows a decrease due to debt reduction
                        if ($balanceChange < 0) {
                            $balanceChange = $settlement->amount;
                        }
                    }
                }

                StatementRecord::create([
                    'user_id' => $user->id,
                    'expense_id' => $expense?->id,
                    'settlement_id' => $settlement?->id,
                    'transaction_type' => $transactionType,
                    'description' => $description,
                    'amount' => $amount,
                    'reference_number' => StatementRecord::generateReferenceNumber(
                        $expense ? 'EXP' : 'PMT'
                    ),
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'balance_change' => $balanceChange,
                    'transaction_details' => [
                        'expense_amount' => $expense?->amount,
                        'settlement_amount' => $settlement?->amount,
                        'transaction_participants' => $users->pluck('name')->toArray(),
                        'affected_balances' => [
                            'owes_before' => $balancesBefore[$user->id]['owes'] ?? [],
                            'owes_after' => $balancesAfter[$user->id]['owes'] ?? [],
                            'receives_before' => $balancesBefore[$user->id]['receives'] ?? [],
                            'receives_after' => $balancesAfter[$user->id]['receives'] ?? [],
                        ],
                        'debt_reductions' => $this->calculateDebtReductions($user->id, $balancesBefore, $balancesAfter, $users)
                    ],
                    'transaction_date' => $expense?->created_at ?? $settlement?->created_at ?? now(),
                    'status' => 'completed'
                ]);
            }
        }
    }

    /**
     * Precise debt reduction using cent-based calculations
     */
    private function autoReduceDebtsForPayerCents(&$netBalancesCents, $paidBy, $expenseAmountCents, $usersAtTime)
    {
        // Find all debts the payer has to others
        $debtsToReduce = [];
        foreach ($usersAtTime as $user) {
            if ($user->id != $paidBy && $netBalancesCents[$paidBy][$user->id] > 0) {
                $debtsToReduce[$user->id] = $netBalancesCents[$paidBy][$user->id];
            }
        }

        if (empty($debtsToReduce)) {
            return; // No debts to reduce
        }

        // Sort debts by amount (highest first)
        arsort($debtsToReduce);

        // Calculate per-person amount that each user owes the payer (in cents)
        $totalUsers = count($usersAtTime);
        $perPersonCents = intval($expenseAmountCents / $totalUsers);
        $remainder = $expenseAmountCents % $totalUsers;

        // Reduce each debt by the amount that specific user owes the payer
        $userIndex = 0;
        foreach ($debtsToReduce as $userId => $debtCents) {
            // Add extra cent to first users if there's a remainder
            $thisPersonAmount = $perPersonCents + ($userIndex < $remainder ? 1 : 0);
            $reductionCents = min($debtCents, $thisPersonAmount);

            // Reduce the debt: payer owes less to this user
            $netBalancesCents[$paidBy][$userId] -= $reductionCents;

            // The other user now owes the payer back the reduction amount
            $netBalancesCents[$userId][$paidBy] += $reductionCents;

            $userIndex++;
        }
    }
}
