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
        $expenses = Expense::with('paidByUser')->latest('expense_date')->get();
        $settlements = Settlement::with(['fromUser', 'toUser'])->latest('settlement_date')->get();
        
        $balances = $this->calculateBalances();
        
        return view('expenses.index', compact('users', 'expenses', 'settlements', 'balances'));
    }

    public function store(Request $request)
    {
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
        ]);

        Settlement::create($validated);

        return redirect()->back()->with('success', 'Settlement recorded successfully!');
    }

    private function calculateBalances()
    {
        $users = User::all();
        $expenses = Expense::all();
        $settlements = Settlement::all();
        
        $balances = [];
        
        foreach ($users as $user) {
            $balances[$user->id] = [
                'name' => $user->name,
                'owes' => [],
                'owed_by' => [],
            ];
        }

        foreach ($expenses as $expense) {
            $perPerson = $expense->amount / 3;
            $paidBy = $expense->paid_by_user_id;

            foreach ($users as $user) {
                if ($user->id != $paidBy) {
                    if (!isset($balances[$user->id]['owes'][$paidBy])) {
                        $balances[$user->id]['owes'][$paidBy] = 0;
                    }
                    if (!isset($balances[$paidBy]['owed_by'][$user->id])) {
                        $balances[$paidBy]['owed_by'][$user->id] = 0;
                    }
                    
                    $balances[$user->id]['owes'][$paidBy] += $perPerson;
                    $balances[$paidBy]['owed_by'][$user->id] += $perPerson;
                }
            }
        }

        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $amount = $settlement->amount;

            if (isset($balances[$fromId]['owes'][$toId])) {
                $balances[$fromId]['owes'][$toId] -= $amount;
                if ($balances[$fromId]['owes'][$toId] <= 0) {
                    unset($balances[$fromId]['owes'][$toId]);
                }
            }

            if (isset($balances[$toId]['owed_by'][$fromId])) {
                $balances[$toId]['owed_by'][$fromId] -= $amount;
                if ($balances[$toId]['owed_by'][$fromId] <= 0) {
                    unset($balances[$toId]['owed_by'][$fromId]);
                }
            }
        }

        return $balances;
    }
}
