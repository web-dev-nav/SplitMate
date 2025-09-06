<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpensePayback;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Http\Request;
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
                'amount' => 'required|numeric|min:0.01',
                'paid_by_user_id' => 'required|exists:users,id',
                'expense_date' => 'required|date',
                'receipt_photo' => 'nullable|image|max:2048',
            ]);

            if ($request->hasFile('receipt_photo')) {
                $validated['receipt_photo'] = $request->file('receipt_photo')->store('receipts', 'public');
            }

            // Set the user count at the time of expense creation
            $validated['user_count_at_time'] = User::count();

            // Create the expense
            Expense::create($validated);

            $message = 'Expense added successfully!';

            return redirect()->back()->with('success', $message);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error adding expense: ' . $e->getMessage());
        }
    }

    public function storeSettlement(Request $request)
    {
        $validated = $request->validate([
            'from_user_id' => 'required|exists:users,id',
            'to_user_id' => 'required|exists:users,id|different:from_user_id',
            'amount' => 'required|numeric|min:0.01',
            'settlement_date' => 'required|date',
            'payment_screenshot' => 'nullable|image|max:2048',
        ]);

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

        // If the payment amount exceeds the debt, return an error
        if ($paymentAmount > $currentDebt) {
            return redirect()->back()->withErrors([
                'amount' => "You can only pay up to $" . number_format($currentDebt, 2) . " (the amount you currently owe)."
            ])->withInput();
        }

        if ($request->hasFile('payment_screenshot')) {
            $validated['payment_screenshot'] = $request->file('payment_screenshot')->store('payment-screenshots', 'public');
        }

        Settlement::create($validated);

        return redirect()->back()->with('success', 'Settlement recorded successfully!');
    }

    private function calculateBalances()
    {
        $users = User::where('is_active', true)->get();
        $expenses = Expense::with('paybacks')->get();
        $settlements = Settlement::all();
        
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
            $usersAtTime = $users->take($totalUsers);

            // Split expense among users who existed at the time (normal splitting)
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    // User owes the person who paid
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // If the person who paid has debts, automatically reduce them by their share
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
        $expenses = Expense::with('paybacks')->get();
        $settlements = Settlement::all();
        
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
            $usersAtTime = $users->take($totalUsers);

            // Split expense among users who existed at the time (normal splitting)
            foreach ($usersAtTime as $user) {
                if ($user->id != $paidBy) {
                    // User owes the person who paid
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }

            // If the person who paid has debts, automatically reduce them by their share
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
                $remainingAmount = $perPerson;
                
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
            
            $expenseDetails[$expense->id] = $details;
        }
        
        return $expenseDetails;
    }

    private function getDebtsBeforeExpense($currentExpense, $users)
    {
        // Get all expenses before this one (by created_at)
        $previousExpenses = Expense::where('created_at', '<', $currentExpense->created_at)
            ->with('paybacks')
            ->get();
        
        $settlements = Settlement::where('created_at', '<', $currentExpense->created_at)->get();
        
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
            $usersAtTime = $users->take($totalUsers);

            // Split expense among users who existed at the time
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

        // Reduce debts starting with the highest
        foreach ($debtsToReduce as $userId => $debtAmount) {
            if ($remainingAmount <= 0) break;

            $reductionAmount = min($debtAmount, $remainingAmount);
            
            // Reduce the debt
            $netBalances[$paidBy][$userId] -= $reductionAmount;
            $netBalances[$userId][$paidBy] += $reductionAmount;
            
            $remainingAmount -= $reductionAmount;
        }
    }
}
