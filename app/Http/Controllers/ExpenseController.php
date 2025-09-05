<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    public function index()
    {
        $users = User::all();
        $expenses = Expense::with(['paidByUser', 'paybackToUser'])->latest('created_at')->paginate(5);
        $settlements = Settlement::with(['fromUser', 'toUser'])->latest('created_at')->paginate(5);
        
        $balances = $this->calculateBalances();
        
        return view('expenses.index', compact('users', 'expenses', 'settlements', 'balances'));
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
                'is_payback' => 'nullable|boolean',
                'payback_to_user_id' => 'nullable|exists:users,id|different:paid_by_user_id',
                'payback_amount' => 'nullable|numeric|min:0.01',
            ]);

            // Handle checkbox - if not checked, set to false
            $validated['is_payback'] = $request->has('is_payback') ? (bool)$request->input('is_payback') : false;

            if ($request->hasFile('receipt_photo')) {
                $validated['receipt_photo'] = $request->file('receipt_photo')->store('receipts', 'public');
            }

            // If it's a payback, set the payback amount to the full expense amount by default
            if ($validated['is_payback'] && !$validated['payback_amount']) {
                $validated['payback_amount'] = $validated['amount'];
            }

            Expense::create($validated);

            $message = $validated['is_payback'] 
                ? 'Expense added and debt reduced successfully!' 
                : 'Expense added successfully!';

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
        ]);

        Settlement::create($validated);

        return redirect()->back()->with('success', 'Settlement recorded successfully!');
    }

    private function calculateBalances()
    {
        $users = User::all();
        $expenses = Expense::all();
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
            $totalUsers = $users->count();
            $perPerson = $totalUsers > 0 ? $expense->amount / $totalUsers : $expense->amount;
            $paidBy = $expense->paid_by_user_id;

            // Handle payback expenses
            if ($expense->is_payback && $expense->payback_to_user_id) {
                $paybackTo = $expense->payback_to_user_id;
                $paybackAmount = $expense->payback_amount;

                // Reduce debt for the person who paid
                $netBalances[$paidBy][$paybackTo] -= $paybackAmount;
                $netBalances[$paybackTo][$paidBy] += $paybackAmount;
            }

            // Split expense among all 3 people
            foreach ($users as $user) {
                if ($user->id != $paidBy) {
                    // User owes the person who paid
                    $netBalances[$user->id][$paidBy] += $perPerson;
                    $netBalances[$paidBy][$user->id] -= $perPerson;
                }
            }
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
}
