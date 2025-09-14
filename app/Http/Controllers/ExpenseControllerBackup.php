<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpensePayback;
use App\Models\Settlement;
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
        $expenses = Expense::with(['paidByUser', 'paybackToUser', 'paybacks.paybackToUser'])->latest('created_at')->paginate(5);
        $settlements = Settlement::with(['fromUser', 'toUser'])->latest('created_at')->paginate(5);
        
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
                $this->storeWalletSnapshot($expense->id, null, $users);
                
                // Save balance state after expense
                $this->saveBalanceState($expense->id, null);

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
                $balances = $this->calculateBalances();
                $fromUserId = $validated['from_user_id'];
                $toUserId = $validated['to_user_id'];
                $paymentAmount = $validated['amount'];

                // Get the current debt amount from the balances structure
                $currentDebt = 0;
                if (isset($balances[$fromUserId]['owes'][$toUserId])) {
                    $currentDebt = $balances[$fromUserId]['owes'][$toUserId];
                }

                // If the payment amount exceeds the debt, throw an exception
                if ($paymentAmount > $currentDebt) {
                    throw new \InvalidArgumentException("You can only pay up to $" . number_format($currentDebt, 2) . " (the amount you currently owe).");
                }

                $settlement = Settlement::create($validated);

                // Store wallet snapshot after settlement
                $users = User::where('is_active', true)->get();
                $this->storeWalletSnapshot(null, $settlement->id, $users);
                
                // Save balance state after settlement
                $this->saveBalanceState(null, $settlement->id);

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
            $perPerson = $totalUsers > 0 ? $expense->amount / $totalUsers : $expense->amount;
            $paidBy = $expense->paid_by_user_id;

            // Get only the users who existed when this expense was created
            if ($expense->participant_ids && count($expense->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                // Fallback for old expenses without participant_ids
                $usersAtTime = $users->take($totalUsers);
            }

            // Split expense among users who existed at the time (normal splitting) FIRST
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    // User owes the person who paid
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // If the person who paid has debts, automatically reduce them by the amount others owe them
            // This is the correct logic: only use the money that others owe the payer for debt reduction
            $amountOthersOwe = $perPerson * (count($usersAtTime) - 1); // Total amount others owe the payer
            
            // Only apply debt reduction if the payer actually has existing debts
            $hasExistingDebts = false;
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }
            
            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $amountOthersOwe, $usersAtTime);
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

    private function calculateDebtsForUser()
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
            $perPerson = $totalUsers > 0 ? $expense->amount / $totalUsers : $expense->amount;
            $paidBy = $expense->paid_by_user_id;

            // Get only the users who existed when this expense was created
            if ($expense->participant_ids && count($expense->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                // Fallback for old expenses without participant_ids
                $usersAtTime = $users->take($totalUsers);
            }

            // Split expense among users who existed at the time (normal splitting) FIRST
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    // User owes the person who paid
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // If the person who paid has debts, automatically reduce them by the amount others owe them
            // This is the correct logic: only use the money that others owe the payer for debt reduction
            $amountOthersOwe = $perPerson * (count($usersAtTime) - 1); // Total amount others owe the payer
            
            // Only apply debt reduction if the payer actually has existing debts
            $hasExistingDebts = false;
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }
            
            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $amountOthersOwe, $usersAtTime);
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

        // Convert to debt format for each user
        $debts = [];
        foreach ($users as $user) {
            $debts[$user->id] = [
                'name' => $user->name,
                'owes' => []
            ];

            foreach ($users as $otherUser) {
                if ($user->id != $otherUser->id) {
                    $netAmount = $netBalances[$user->id][$otherUser->id];
                    
                    if ($netAmount > 0) {
                        // User owes money to otherUser
                        $debts[$user->id]['owes'][$otherUser->id] = [
                            'name' => $otherUser->name,
                            'amount' => $netAmount
                        ];
                    }
                }
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
            $perPerson = $totalUsers > 0 ? $expense->amount / $totalUsers : $expense->amount;
            $paidBy = $expense->paid_by_user_id;
            
            $details = [
                'expense_id' => $expense->id,
                'description' => $expense->description,
                'amount' => $expense->amount,
                'per_person' => $perPerson,
                'paid_by' => $expense->paidByUser->name,
                'paid_by_id' => $paidBy,
                'expense_date' => $expense->expense_date,
                'debt_reductions' => [],
                'normal_splits' => []
            ];
            
            // Calculate what debts existed before this expense
            $debtsBefore = $this->getDebtsBeforeExpense($expense, $users);
            
            // Calculate normal splits (who owes what to the payer)
            foreach ($users as $user) {
                if ($user->id != $paidBy) {
                    $details['normal_splits'][] = [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'owes_amount' => $perPerson
                    ];
                }
            }
            
            // Calculate debt reductions for the payer
            if (isset($debtsBefore[$paidBy])) {
                // Get only the users who existed when this expense was created
                if ($expense->participant_ids && count($expense->participant_ids) > 0) {
                    $usersAtTime = User::whereIn('id', $expense->participant_ids)->get();
                } else {
                    $usersAtTime = $users;
                }
                
                // Sort debts by amount (highest first)
                $sortedDebts = $debtsBefore[$paidBy];
                arsort($sortedDebts);
                
                foreach ($sortedDebts as $userId => $debtAmount) {
                    // Only apply debt reduction if this user participated in the current expense
                    $userParticipated = $usersAtTime->contains('id', $userId);
                    if (!$userParticipated) continue;
                    
                    // Calculate how much this specific user owes the payer from this expense
                    $userOwesPayer = $perPerson; // Each user owes the payer $perPerson
                    
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
            
            // Get wallet balance snapshot after this specific expense only
            $details['wallet_snapshot'] = $this->getWalletSnapshotAfterSpecificExpense($expense, $users);
            
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
            // Only use the amount that others owe the payer for debt reduction
            $amountOthersOwe = $perPerson * (count($usersAtTime) - 1);
            $this->autoReduceDebtsForPayer($netBalances, $paidBy, $amountOthersOwe, $usersAtTime);
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

    private function autoReduceDebtsForPayer(&$netBalances, $paidBy, $amountOthersOwe, $usersAtTime)
    {
        // Find all debts the payer has to others
        $debtsToReduce = [];
        foreach ($usersAtTime as $user) {
            if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                $debtsToReduce[$user->id] = $netBalances[$paidBy][$user->id];
            }
        }

        if (empty($debtsToReduce)) {
            return; // No debts to reduce
        }

        // Sort debts by amount (highest first)
        arsort($debtsToReduce);

        // Calculate per-person amount that each user owes the payer
        $perPerson = $amountOthersOwe / (count($usersAtTime) - 1); // -1 because payer doesn't owe themselves
        
        // Reduce each debt by the amount that specific user owes the payer
        foreach ($debtsToReduce as $userId => $debtAmount) {
            // The reduction is the minimum of: debt amount OR what this user owes the payer
            $reductionAmount = min($debtAmount, $perPerson);
            
            // Reduce the debt: payer owes less to this user
            $netBalances[$paidBy][$userId] -= $reductionAmount;
            
            // The other user now owes the payer back the reduction amount
            $netBalances[$userId][$paidBy] += $reductionAmount;
        }
    }

    private function storeWalletSnapshot($expenseId = null, $settlementId = null, $users)
    {
        // Calculate current wallet balances (this already includes debt reductions)
        $balances = $this->calculateBalances();
        
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
            // Only use the amount that others owe the payer for debt reduction
            $amountOthersOwe = $perPerson * (count($usersAtTime) - 1);
            $this->autoReduceDebtsForPayer($netBalances, $paidBy, $amountOthersOwe, $usersAtTime);
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
        $balances = $this->calculateBalances();
        
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
    private function calculateBalancesCorrectly()
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
            $perPerson = $totalUsers > 0 ? $expense->amount / $totalUsers : $expense->amount;
            $paidBy = $expense->paid_by_user_id;

            // Get only the users who existed when this expense was created
            if ($expense->participant_ids && count($expense->participant_ids) > 0) {
                $usersAtTime = User::whereIn('id', $expense->participant_ids)->get();
            } else {
                // Fallback for old expenses without participant_ids
                $usersAtTime = $users->take($totalUsers);
            }

            // Split expense among users who existed at the time (normal splitting) FIRST
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    // User owes the person who paid
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // Only apply debt reduction if the payer actually has existing debts
            $amountOthersOwe = $perPerson * (count($usersAtTime) - 1);
            $hasExistingDebts = false;
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }
            
            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $amountOthersOwe, $usersAtTime);
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
            $amountOthersOwe = $perPerson * (count($usersAtTime) - 1);
            $hasExistingDebts = false;
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }
            
            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $amountOthersOwe, $usersAtTime);
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
            
            // Calculate net balance
            $netBalance = 0;
            foreach ($walletBalances[$user->id]['owes'] as $amount) {
                $netBalance -= $amount;
            }
            foreach ($walletBalances[$user->id]['receives'] as $amount) {
                $netBalance += $amount;
            }
            $walletBalances[$user->id]['net_balance'] = $netBalance;
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
            $amountOthersOwe = $perPerson * (count($usersAtTime) - 1);
            $hasExistingDebts = false;
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy && $netBalances[$paidBy][$user->id] > 0) {
                    $hasExistingDebts = true;
                    break;
                }
            }
            
            if ($hasExistingDebts) {
                $this->autoReduceDebtsForPayer($netBalances, $paidBy, $amountOthersOwe, $usersAtTime);
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
            
            // Calculate net balance
            $netBalance = 0;
            foreach ($walletBalances[$user->id]['owes'] as $amount) {
                $netBalance -= $amount;
            }
            foreach ($walletBalances[$user->id]['receives'] as $amount) {
                $netBalance += $amount;
            }
            $walletBalances[$user->id]['net_balance'] = $netBalance;
        }

        return $walletBalances;
    }
}
