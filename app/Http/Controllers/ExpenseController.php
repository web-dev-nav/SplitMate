<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpensePayback;
use App\Models\Settlement;
use App\Models\User;
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
        
        $balances = $this->calculateBalances();
        
        // Calculate debts for automatic payback suggestions
        $debts = $this->calculateDebtsForUser();
        
        // Calculate debt reduction details for each expense
        $expenseDetails = $this->calculateExpenseDetails($expenses, $users);
        
        return view('expenses.index', compact('users', 'expenses', 'settlements', 'balances', 'debts', 'expenseDetails'));
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
            $this->autoReduceDebtsForPayer($netBalances, $paidBy, $amountOthersOwe, $usersAtTime);
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
            $this->autoReduceDebtsForPayer($netBalances, $paidBy, $amountOthersOwe, $usersAtTime);
        }

        // Process settlements
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            $netBalances[$fromId][$toId] -= $amount;
            $netBalances[$toId][$fromId] += $amount;
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
                // Only use the amount that others owe the payer for debt reduction
                $remainingAmount = $perPerson * (count($users) - 1); // Total amount others owe the payer
                
                // Sort debts by amount (highest first)
                $sortedDebts = $debtsBefore[$paidBy];
                arsort($sortedDebts);
                
                foreach ($sortedDebts as $userId => $debtAmount) {
                    if ($remainingAmount <= 0) break;
                    
                    $reductionAmount = min($debtAmount, $remainingAmount);
                    $remainingAmount -= $reductionAmount;
                    
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
            
            // Get wallet balance snapshot from database after this expense
            $details['wallet_snapshot'] = $this->getWalletSnapshotFromDB($expense->id, null);
            
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

        // Process settlements
        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            $netBalances[$fromId][$toId] -= $amount;
            $netBalances[$toId][$fromId] += $amount;
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
}
