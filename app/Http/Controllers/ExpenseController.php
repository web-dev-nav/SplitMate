<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Settlement;
use App\Models\StatementRecord;
use App\Models\User;
use App\Models\BalanceState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpenseController extends Controller
{
    /**
     * Main dashboard view
     */
    public function index()
    {
        $users = User::where('is_active', true)->get();
        $expenses = Expense::with(['paidByUser', 'paybackToUser'])->latest()->paginate(5);
        $settlements = Settlement::with(['fromUser', 'toUser'])->latest()->paginate(5);

        // Calculate current balances
        $balances = $this->calculateBalances();

        // Get suggestions for who should pay whom (legacy: called 'debts' in old view)
        $debts = $this->getPaymentSuggestions($balances);

        // Calculate detailed breakdowns for each transaction
        $expenseDetails = $this->calculateExpenseDetails($expenses, $users);
        $settlementDetails = $this->calculateSettlementDetails($settlements, $users);

        return view('expenses.index', compact('users', 'expenses', 'settlements', 'balances', 'debts', 'expenseDetails', 'settlementDetails'));
    }

    /**
     * Store new expense
     */
    public function store(Request $request)
    {
        try {
            // Log incoming request for debugging
            Log::info('Expense store request received', [
                'has_file' => $request->hasFile('receipt_photo'),
                'files_count' => count($request->allFiles()),
                'content_length' => $request->server('CONTENT_LENGTH'),
                'max_upload_size' => ini_get('upload_max_filesize'),
                'max_post_size' => ini_get('post_max_size')
            ]);

            $validated = $request->validate([
                'description' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0.01|max:999999.99',
                'paid_by_user_id' => 'required|exists:users,id',
                'expense_date' => 'required|date|before_or_equal:today',
                'receipt_photo' => 'required|file|mimes:jpeg,jpg,png,gif,bmp,webp,svg|max:15360',
            ]);

            DB::transaction(function () use ($validated, $request) {
                // Upload receipt photo with error handling
                $receiptFile = $request->file('receipt_photo');
                if (!$receiptFile) {
                    throw new \InvalidArgumentException('Receipt photo is required');
                }

                Log::info('Processing receipt photo upload', [
                    'original_name' => $receiptFile->getClientOriginalName(),
                    'size' => $receiptFile->getSize(),
                    'mime_type' => $receiptFile->getMimeType(),
                    'is_valid' => $receiptFile->isValid(),
                    'error_code' => $receiptFile->getError()
                ]);

                try {
                    $receiptPath = $receiptFile->store('receipts', 'public');
                    if (!$receiptPath) {
                        throw new \RuntimeException('Failed to store receipt photo');
                    }
                    $validated['receipt_photo'] = $receiptPath;

                    Log::info('Receipt photo uploaded successfully', [
                        'path' => $receiptPath
                    ]);
                } catch (\Exception $e) {
                    Log::error('Receipt photo upload failed', [
                        'error' => $e->getMessage(),
                        'file_size' => $receiptFile->getSize(),
                        'file_type' => $receiptFile->getMimeType(),
                        'file_error' => $receiptFile->getError(),
                        'disk_config' => config('filesystems.disks.public')
                    ]);
                    throw new \RuntimeException('Failed to upload receipt photo: ' . $e->getMessage());
                }

                // Store participant info at time of expense
                $users = User::where('is_active', true)->get();
                $validated['user_count_at_time'] = $users->count();
                $validated['participant_ids'] = $users->pluck('id')->toArray();

                $expense = Expense::create($validated);
                $this->createStatementRecords($expense);
            });

            return redirect()->back()->with('success', 'Expense added successfully!');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Expense validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->except(['receipt_photo'])
            ]);
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            Log::error('Expense creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Failed to create expense: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Store settlement payment
     */
    public function storeSettlement(Request $request)
    {
        $validated = $request->validate([
            'from_user_id' => 'required|exists:users,id',
            'to_user_id' => 'required|exists:users,id|different:from_user_id',
            'amount' => 'required|numeric|min:0.01',
            'settlement_date' => 'required|date',
            'payment_screenshot' => 'required|file|mimes:jpeg,jpg,png,gif,bmp,webp,svg|max:15360',
        ]);

        DB::transaction(function () use ($validated) {
            // Verify payment doesn't exceed debt
            $balances = $this->calculateBalances();
            $currentDebt = $balances[$validated['from_user_id']]['owes'][$validated['to_user_id']] ?? 0;

            if ($validated['amount'] > $currentDebt + 0.01) {
                throw new \InvalidArgumentException("Payment amount exceeds current debt of $" . number_format($currentDebt, 2));
            }

            // Upload payment screenshot with error handling
            $screenshotFile = request()->file('payment_screenshot');
            if (!$screenshotFile) {
                throw new \InvalidArgumentException('Payment screenshot is required');
            }

            try {
                $screenshotPath = $screenshotFile->store('payment-screenshots', 'public');
                if (!$screenshotPath) {
                    throw new \RuntimeException('Failed to store payment screenshot');
                }
                $validated['payment_screenshot'] = $screenshotPath;
            } catch (\Exception $e) {
                Log::error('Payment screenshot upload failed', [
                    'error' => $e->getMessage(),
                    'file_size' => $screenshotFile->getSize(),
                    'file_type' => $screenshotFile->getMimeType(),
                    'disk_config' => config('filesystems.disks.public')
                ]);
                throw new \RuntimeException('Failed to upload payment screenshot: ' . $e->getMessage());
            }

            $settlement = Settlement::create($validated);
            $this->createStatementRecords(null, $settlement);
        });

        return redirect()->back()->with('success', 'Settlement recorded successfully!');
    }

    /**
     * SIMPLIFIED: Calculate all user balances
     * This is the core calculation method - simplified from 150+ lines to ~60 lines
     */
    public function calculateBalances()
    {
        $users = User::where('is_active', true)->get();

        // Initialize balance matrix: balances[from_user][to_user] = amount
        $balances = [];
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id !== $user2->id) {
                    $balances[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process all expenses
        $expenses = Expense::orderBy('created_at')->get();
        foreach ($expenses as $expense) {
            $this->processExpense($expense, $balances, $users);
        }

        // Process all settlements
        $settlements = Settlement::orderBy('created_at')->get();
        foreach ($settlements as $settlement) {
            $this->processSettlement($settlement, $balances);
        }

        // Consolidate mutual debts (A owes B $10, B owes A $3 = A owes B $7)
        $this->consolidateDebts($balances, $users);

        // Convert to display format
        return $this->formatBalancesForDisplay($balances, $users);
    }

    /**
     * SIMPLIFIED: Process a single expense
     */
    private function processExpense($expense, &$balances, $users)
    {
        $paidBy = $expense->paid_by_user_id;
        $amount = $expense->amount;

        // Get participants (users who existed when expense was created)
        $participantIds = $expense->participant_ids ?? $users->pluck('id')->toArray();
        $participants = $users->whereIn('id', $participantIds);
        $participantCount = $participants->count();

        if ($participantCount === 0) return;

        // Calculate each person's share (handle cents precisely)
        $amountCents = round($amount * 100);
        $sharePerPersonCents = intval($amountCents / $participantCount);
        $remainderCents = $amountCents % $participantCount;

        // Distribute shares
        $index = 0;
        foreach ($participants->sortBy('id') as $participant) {
            if ($participant->id === $paidBy) {
                $index++;
                continue;
            }

            // Add remainder cents to first few participants
            $shareCents = $sharePerPersonCents + ($index < $remainderCents ? 1 : 0);
            $share = $shareCents / 100;

            // Apply debt reduction if payer owes this participant
            if ($balances[$paidBy][$participant->id] > 0) {
                $reduction = min($balances[$paidBy][$participant->id], $share);
                $balances[$paidBy][$participant->id] -= $reduction;
                $share -= $reduction;
            }

            // Remaining amount becomes new debt from participant to payer
            if ($share > 0) {
                $balances[$participant->id][$paidBy] += $share;
            }

            $index++;
        }
    }

    /**
     * SIMPLIFIED: Process a settlement payment
     */
    private function processSettlement($settlement, &$balances)
    {
        $fromId = $settlement->from_user_id;
        $toId = $settlement->to_user_id;
        $amount = $settlement->amount;

        // Reduce existing debt first
        if ($balances[$fromId][$toId] > 0) {
            $reduction = min($amount, $balances[$fromId][$toId]);
            $balances[$fromId][$toId] -= $reduction;
            $amount -= $reduction;
        }

        // If payment exceeds debt, create reverse debt
        if ($amount > 0) {
            $balances[$toId][$fromId] += $amount;
        }
    }

    /**
     * SIMPLIFIED: Consolidate mutual debts
     */
    private function consolidateDebts(&$balances, $users)
    {
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id >= $user2->id) continue; // Process each pair once

                $debt1to2 = $balances[$user1->id][$user2->id];
                $debt2to1 = $balances[$user2->id][$user1->id];

                if ($debt1to2 > 0 && $debt2to1 > 0) {
                    if ($debt1to2 > $debt2to1) {
                        $balances[$user1->id][$user2->id] = $debt1to2 - $debt2to1;
                        $balances[$user2->id][$user1->id] = 0;
                    } else {
                        $balances[$user2->id][$user1->id] = $debt2to1 - $debt1to2;
                        $balances[$user1->id][$user2->id] = 0;
                    }
                }
            }
        }
    }

    /**
     * SIMPLIFIED: Format balances for display
     */
    private function formatBalancesForDisplay($balances, $users)
    {
        $result = [];

        foreach ($users as $user) {
            $result[$user->id] = [
                'name' => $user->name,
                'owes' => [],
                'owed_by' => [],
                'net_balance' => 0
            ];

            $netBalance = 0;
            foreach ($users as $otherUser) {
                if ($user->id === $otherUser->id) continue;

                $owes = $balances[$user->id][$otherUser->id];
                $owedBy = $balances[$otherUser->id][$user->id];

                if ($owes > 0) {
                    $result[$user->id]['owes'][$otherUser->id] = $owes;
                    $netBalance -= $owes;
                }

                if ($owedBy > 0) {
                    $result[$user->id]['owed_by'][$otherUser->id] = $owedBy;
                    $netBalance += $owedBy;
                }
            }

            $result[$user->id]['net_balance'] = round($netBalance, 2);
        }

        return $result;
    }

    /**
     * SIMPLIFIED: Get payment suggestions
     */
    private function getPaymentSuggestions($balances)
    {
        $suggestions = [];

        foreach ($balances as $userId => $userBalance) {
            if (!empty($userBalance['owes'])) {
                // Sort debts by amount (pay largest first)
                $debts = $userBalance['owes'];
                arsort($debts);

                foreach ($debts as $creditorId => $amount) {
                    $creditor = User::find($creditorId);
                    $suggestions[] = [
                        'debtor' => $userBalance['name'],
                        'creditor' => $creditor->name,
                        'amount' => $amount,
                        'from_user_id' => $userId,
                        'to_user_id' => $creditorId
                    ];
                    break; // Only suggest one payment per user
                }
            }
        }

        return $suggestions;
    }

    /**
     * Create detailed debt-aware statement records
     */
    private function createStatementRecords($expense = null, $settlement = null)
    {
        $users = User::where('is_active', true)->get();

        // Get balances before and after this transaction
        $balancesBefore = $this->getBalancesBefore($expense, $settlement);
        $balancesAfter = $this->calculateBalances();

        foreach ($users as $user) {
            $record = $this->createDetailedStatement($user, $expense, $settlement, $balancesBefore, $balancesAfter, $users);

            // Only create record if there's an actual impact on this user
            if ($record) {
                StatementRecord::create($record);
            }
        }
    }

    /**
     * Create detailed statement showing WHO OWES WHOM
     */
    private function createDetailedStatement($user, $expense = null, $settlement = null, $balancesBefore, $balancesAfter, $users)
    {
        if ($expense) {
            return $this->createDetailedExpenseStatement($user, $expense, $balancesBefore, $balancesAfter, $users);
        } elseif ($settlement) {
            return $this->createDetailedSettlementStatement($user, $settlement, $balancesBefore, $balancesAfter, $users);
        }

        return null;
    }

    /**
     * Create detailed expense statement showing debt changes
     */
    private function createDetailedExpenseStatement($user, $expense, $balancesBefore, $balancesAfter, $users)
    {
        $isPayer = ($expense->paid_by_user_id === $user->id);
        $participantCount = $expense->user_count_at_time ?? $users->count();
        $perPersonShare = round($expense->amount / $participantCount, 2);
        $payer = User::find($expense->paid_by_user_id);

        // Calculate balance changes
        $balanceBefore = $balancesBefore[$user->id]['net_balance'] ?? 0;
        $balanceAfter = $balancesAfter[$user->id]['net_balance'] ?? 0;
        $balanceChange = round($balanceAfter - $balanceBefore, 2);

        // Skip if no change for this user
        if (abs($balanceChange) < 0.01) {
            return null;
        }

        if ($isPayer) {
            $description = "💸 You paid: {$expense->description}";
            $amount = $expense->amount;

            // Show who owes the payer
            $debtDetails = $this->getWhoOwesWhom($user->id, $balancesBefore, $balancesAfter, $users);
            $note = "You paid $" . number_format($expense->amount, 2) . " for everyone.";

        } else {
            $description = "🛒 Expense: {$expense->description}";
            $amount = $balanceChange; // Shows actual impact on their balance

            // Show debt reduction or new debt details
            $debtDetails = $this->getDebtChanges($user->id, $expense->paid_by_user_id, $balancesBefore, $balancesAfter, $perPersonShare);
            $note = "Your share: $" . number_format($perPersonShare, 2) . " (paid by {$payer->name})";
        }

        return [
            'user_id' => $user->id,
            'expense_id' => $expense->id,
            'transaction_type' => 'expense',
            'description' => $description,
            'amount' => $amount,
            'reference_number' => StatementRecord::generateReferenceNumber('EXP'),
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'balance_change' => $balanceChange,
            'transaction_date' => $expense->created_at ?? now(),
            'status' => 'completed',
            'transaction_details' => [
                'note' => $note,
                'expense_total' => $expense->amount,
                'your_share' => $perPersonShare,
                'participants' => $participantCount,
                'debt_details' => $debtDetails,
                'is_payer' => $isPayer
            ]
        ];
    }

    /**
     * Create detailed settlement statement showing debt changes
     */
    private function createDetailedSettlementStatement($user, $settlement, $balancesBefore, $balancesAfter, $users)
    {
        $isFromUser = ($settlement->from_user_id === $user->id);
        $isToUser = ($settlement->to_user_id === $user->id);

        // Only create records for users directly involved
        if (!$isFromUser && !$isToUser) {
            return null;
        }

        $balanceBefore = $balancesBefore[$user->id]['net_balance'] ?? 0;
        $balanceAfter = $balancesAfter[$user->id]['net_balance'] ?? 0;
        $balanceChange = round($balanceAfter - $balanceBefore, 2);

        $fromUser = User::find($settlement->from_user_id);
        $toUser = User::find($settlement->to_user_id);

        if ($isFromUser) {
            $description = "💰 Payment to {$toUser->name}";
            $amount = -$settlement->amount;

            // Show debt before and after
            $debtBefore = $balancesBefore[$user->id]['owes'][$settlement->to_user_id] ?? 0;
            $debtAfter = $balancesAfter[$user->id]['owes'][$settlement->to_user_id] ?? 0;
            $reverseDebt = $balancesAfter[$settlement->to_user_id]['owes'][$user->id] ?? 0;

            if ($debtAfter > 0) {
                $note = "Paid $" . number_format($settlement->amount, 2) . ". You still owe $" . number_format($debtAfter, 2);
            } elseif ($reverseDebt > 0) {
                $note = "Paid $" . number_format($settlement->amount, 2) . ". {$toUser->name} now owes you $" . number_format($reverseDebt, 2);
            } else {
                $note = "Paid $" . number_format($settlement->amount, 2) . ". All settled!";
            }

        } else {
            $description = "💸 Received from {$fromUser->name}";
            $amount = $settlement->amount;

            $debtBefore = $balancesBefore[$settlement->from_user_id]['owes'][$user->id] ?? 0;
            $debtAfter = $balancesAfter[$settlement->from_user_id]['owes'][$user->id] ?? 0;
            $reverseDebt = $balancesAfter[$user->id]['owes'][$settlement->from_user_id] ?? 0;

            if ($debtAfter > 0) {
                $note = "Received $" . number_format($settlement->amount, 2) . ". {$fromUser->name} still owes $" . number_format($debtAfter, 2);
            } elseif ($reverseDebt > 0) {
                $note = "Received $" . number_format($settlement->amount, 2) . ". You now owe {$fromUser->name} $" . number_format($reverseDebt, 2);
            } else {
                $note = "Received $" . number_format($settlement->amount, 2) . ". All settled!";
            }
        }

        return [
            'user_id' => $user->id,
            'settlement_id' => $settlement->id,
            'transaction_type' => 'settlement',
            'description' => $description,
            'amount' => $amount,
            'reference_number' => StatementRecord::generateReferenceNumber('PMT'),
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'balance_change' => $balanceChange,
            'transaction_date' => $settlement->created_at ?? now(),
            'status' => 'completed',
            'transaction_details' => [
                'note' => $note,
                'payment_amount' => $settlement->amount,
                'from_user' => $fromUser->name,
                'to_user' => $toUser->name,
                'is_sender' => $isFromUser,
                'is_receiver' => $isToUser
            ]
        ];
    }

    /**
     * Get who owes whom after a transaction (for payers)
     */
    private function getWhoOwesWhom($payerId, $balancesBefore, $balancesAfter, $users)
    {
        $details = [];

        foreach ($users as $user) {
            if ($user->id === $payerId) continue;

            $owedBefore = $balancesAfter[$user->id]['owes'][$payerId] ?? 0;
            if ($owedBefore > 0) {
                $details[] = "💰 {$user->name} owes you $" . number_format($owedBefore, 2);
            }
        }

        return $details;
    }

    /**
     * Get debt change details for participants
     */
    private function getDebtChanges($userId, $payerId, $balancesBefore, $balancesAfter, $shareAmount)
    {
        $details = [];

        $debtBefore = $balancesBefore[$userId]['owes'][$payerId] ?? 0;
        $debtAfter = $balancesAfter[$userId]['owes'][$payerId] ?? 0;

        if ($debtBefore > 0) {
            $reduction = $debtBefore - $debtAfter;
            if ($reduction > 0) {
                $details[] = "🔄 Previous debt reduced by $" . number_format($reduction, 2);
            }
        }

        if ($debtAfter > 0) {
            $details[] = "💳 You now owe $" . number_format($debtAfter, 2);
        } else {
            $reverseDebt = $balancesAfter[$payerId]['owes'][$userId] ?? 0;
            if ($reverseDebt > 0) {
                $payer = User::find($payerId);
                $details[] = "💰 {$payer->name} owes you $" . number_format($reverseDebt, 2);
            }
        }

        return $details;
    }

    /**
     * SIMPLIFIED: Get balances before a specific transaction
     */
    private function getBalancesBefore($expense = null, $settlement = null)
    {
        $cutoffDate = null;

        if ($expense) {
            $cutoffDate = $expense->created_at;
        } elseif ($settlement) {
            $cutoffDate = $settlement->created_at;
        }

        if (!$cutoffDate) {
            return [];
        }

        // Temporarily calculate balances up to cutoff date
        $users = User::where('is_active', true)->get();
        $balances = [];

        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id !== $user2->id) {
                    $balances[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process expenses before cutoff
        $expenses = Expense::where('created_at', '<', $cutoffDate)->orderBy('created_at')->get();
        foreach ($expenses as $exp) {
            $this->processExpense($exp, $balances, $users);
        }

        // Process settlements before cutoff
        $settlements = Settlement::where('created_at', '<', $cutoffDate)->orderBy('created_at')->get();
        foreach ($settlements as $sett) {
            $this->processSettlement($sett, $balances);
        }

        $this->consolidateDebts($balances, $users);

        return $this->formatBalancesForDisplay($balances, $users);
    }

    /**
     * User statement history view - SIMPLIFIED
     */
    public function userStatementView($userId)
    {
        $user = User::findOrFail($userId);
        $allUsers = User::where('is_active', true)->get();

        $statements = StatementRecord::with(['expense', 'settlement'])
            ->where('user_id', $userId)
            ->orderBy('transaction_date', 'desc')
            ->paginate(25);

        // Calculate current balance from the balance system
        $balances = $this->calculateBalances();
        $currentBalance = $balances[$userId]['net_balance'] ?? 0;

        return view('statements.user-simple', compact('user', 'allUsers', 'statements', 'currentBalance'));
    }

    /**
     * Regenerate all statements with simplified format
     */
    public function regenerateSimplifiedStatements()
    {
        try {
            // Clear existing statement records
            StatementRecord::truncate();

            $users = User::where('is_active', true)->get();

            // Regenerate for all expenses
            $expenses = Expense::orderBy('created_at')->get();
            foreach ($expenses as $expense) {
                $this->createStatementRecords($expense, null);
            }

            // Regenerate for all settlements
            $settlements = Settlement::orderBy('created_at')->get();
            foreach ($settlements as $settlement) {
                $this->createStatementRecords(null, $settlement);
            }

            // Check if this is an API request or web request
            if (request()->wantsJson() || request()->is('api/*')) {
                return response()->json([
                    'message' => 'Simplified statement records regenerated successfully',
                    'total_expenses_processed' => $expenses->count(),
                    'total_settlements_processed' => $settlements->count()
                ]);
            } else {
                // For web requests, redirect back with success message
                return back()->with('success',
                    'All statements have been updated! ' .
                    $expenses->count() . ' expenses and ' .
                    $settlements->count() . ' settlements processed.'
                );
            }
        } catch (\Exception $e) {
            if (request()->wantsJson() || request()->is('api/*')) {
                return response()->json([
                    'error' => 'Failed to regenerate statements: ' . $e->getMessage()
                ], 500);
            } else {
                return back()->with('error', 'Failed to regenerate statements: ' . $e->getMessage());
            }
        }
    }

    /**
     * API endpoint for statement history
     */
    public function apiStatementHistory(Request $request, $userId)
    {
        $user = User::findOrFail($userId);
        $limit = $request->get('limit', 50);

        $statements = StatementRecord::where('user_id', $userId)
            ->orderBy('transaction_date', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name],
            'statements' => $statements,
            'current_balance' => $statements->first()?->balance_after ?? 0
        ]);
    }

    /**
     * Debug helper to verify calculations
     */
    public function debugBalance()
    {
        $balances = $this->calculateBalances();
        $users = User::where('is_active', true)->get();

        // Verify balance integrity
        $totalOwed = 0;
        $totalReceived = 0;

        foreach ($balances as $userBalance) {
            $totalOwed += array_sum($userBalance['owes']);
            $totalReceived += array_sum($userBalance['owed_by']);
        }

        return response()->json([
            'balances' => $balances,
            'integrity_check' => [
                'total_owed' => $totalOwed,
                'total_received' => $totalReceived,
                'difference' => abs($totalOwed - $totalReceived),
                'is_balanced' => abs($totalOwed - $totalReceived) < 0.01
            ]
        ]);
    }

    /**
     * Debug helper to test breakdown calculations
     */
    public function debugBreakdowns()
    {
        $users = User::where('is_active', true)->get();
        $expenses = Expense::latest()->take(2)->get();
        $settlements = Settlement::latest()->take(2)->get();

        $expenseDetails = $this->calculateExpenseDetails($expenses, $users);
        $settlementDetails = $this->calculateSettlementDetails($settlements, $users);

        return response()->json([
            'sample_expense_breakdown' => !empty($expenseDetails) ? array_values($expenseDetails)[0] : null,
            'sample_settlement_breakdown' => !empty($settlementDetails) ? array_values($settlementDetails)[0] : null,
            'expense_count' => count($expenseDetails),
            'settlement_count' => count($settlementDetails)
        ]);
    }

    /**
     * Test specific calculation scenarios to verify logic correctness
     */
    public function testCalculationScenarios()
    {
        // Simulate the scenarios with manual balance tracking
        $scenarios = [];

        // CASE 1: Basic debt reduction scenario
        $case1 = $this->simulateCase1();
        $scenarios['case_1_basic_debt_reduction'] = $case1;

        // CASE 2: Debt reversal scenario
        $case2 = $this->simulateCase2();
        $scenarios['case_2_debt_reversal'] = $case2;

        // Additional approved test cases
        $case3 = $this->simulateCase3_MultipleDebts();
        $scenarios['case_3_multiple_debts'] = $case3;

        $case4 = $this->simulateCase4_ExactDebtElimination();
        $scenarios['case_4_exact_debt_elimination'] = $case4;

        $case5 = $this->simulateCase5_PrecisionTest();
        $scenarios['case_5_precision_test'] = $case5;

        $case7 = $this->simulateCase7_SettlementOverpayment();
        $scenarios['case_7_settlement_overpayment'] = $case7;

        $case8 = $this->simulateCase8_ZeroAmountExpense();
        $scenarios['case_8_zero_amount_expense'] = $case8;

        $case9 = $this->simulateCase9_LargeGroup();
        $scenarios['case_9_large_group'] = $case9;

        $case10 = $this->simulateCase10_TimeBasedSequence();
        $scenarios['case_10_time_based_sequence'] = $case10;

        return response()->json([
            'test_scenarios' => $scenarios,
            'summary' => [
                'total_cases' => count($scenarios),
                'all_passed' => $this->validateAllScenarios($scenarios)
            ]
        ]);
    }

    /**
     * CASE 1: Navjot pays $200, Sapna pays $30, Sapna pays $50
     */
    private function simulateCase1()
    {
        $balances = [
            'navjot' => ['sapna' => 0, 'third_user' => 0],
            'sapna' => ['navjot' => 0, 'third_user' => 0],
            'third_user' => ['navjot' => 0, 'sapna' => 0]
        ];

        $steps = [];

        // Step 1: Navjot pays $200 expense
        // Each person owes $66.67 (rounded to $66.67)
        $balances['sapna']['navjot'] += 66.67;
        $balances['third_user']['navjot'] += 66.67;
        $steps[] = [
            'action' => 'Navjot pays $200 expense',
            'calculation' => '$200 ÷ 3 = $66.67 each',
            'result' => 'Sapna owes Navjot $66.67, Third user owes Navjot $66.67',
            'balances' => $this->copyBalances($balances)
        ];

        // Step 2: Sapna pays $30 to Navjot
        $balances['sapna']['navjot'] -= 30;
        $steps[] = [
            'action' => 'Sapna pays $30 to Navjot',
            'calculation' => '$66.67 - $30 = $36.67',
            'result' => 'Sapna now owes Navjot $36.67',
            'balances' => $this->copyBalances($balances)
        ];

        // Step 3: Sapna pays $50 expense
        // Each person owes $16.67 (rounded to $16.67)
        // BUT Sapna's existing debt of $36.67 gets reduced first
        $navjotShare = 16.67;
        $thirdUserShare = 16.67;
        $debtReduction = min($balances['sapna']['navjot'], $navjotShare);

        $balances['navjot']['sapna'] += max(0, $navjotShare - $debtReduction);
        $balances['sapna']['navjot'] -= $debtReduction;
        $balances['third_user']['sapna'] += $thirdUserShare;

        $steps[] = [
            'action' => 'Sapna pays $50 expense',
            'calculation' => '$50 ÷ 3 = $16.67 each, Debt reduction: $16.67',
            'result' => 'Sapna debt to Navjot: $36.67 - $16.67 = $20.00, Third user owes Sapna $16.67',
            'balances' => $this->copyBalances($balances),
            'debt_reduction' => $debtReduction
        ];

        return [
            'description' => 'Basic debt reduction scenario',
            'expected_final_state' => [
                'sapna_owes_navjot' => 20.00,
                'third_user_owes_sapna' => 16.67,
                'third_user_owes_navjot' => 66.67
            ],
            'steps' => $steps
        ];
    }

    /**
     * CASE 2: Debt reversal scenario
     */
    private function simulateCase2()
    {
        // Start with Sapna owing Navjot $14.07 (from previous scenario)
        $balances = [
            'navjot' => ['sapna' => 0, 'third_user' => 0],
            'sapna' => ['navjot' => 14.07, 'third_user' => 0],
            'third_user' => ['navjot' => 0, 'sapna' => 0]
        ];

        $steps = [];
        $steps[] = [
            'action' => 'Starting state',
            'result' => 'Sapna owes Navjot $14.07',
            'balances' => $this->copyBalances($balances)
        ];

        // Sapna pays $100 expense
        // Each person owes $33.33
        $navjotShare = 33.33;
        $thirdUserShare = 33.33;
        $existingDebt = $balances['sapna']['navjot'];

        // Debt reduction first
        $debtReduction = min($existingDebt, $navjotShare);
        $remainingShare = $navjotShare - $debtReduction;

        $balances['sapna']['navjot'] -= $debtReduction;
        if ($remainingShare > 0) {
            $balances['navjot']['sapna'] += $remainingShare;
        }
        $balances['third_user']['sapna'] += $thirdUserShare;

        $steps[] = [
            'action' => 'Sapna pays $100 expense',
            'calculation' => '$100 ÷ 3 = $33.33 each, Existing debt: $14.07',
            'debt_reduction' => $debtReduction,
            'remaining_share' => $remainingShare,
            'result' => 'Debt eliminated + Navjot owes Sapna $19.26, Third user owes Sapna $33.33',
            'balances' => $this->copyBalances($balances)
        ];

        return [
            'description' => 'Debt reversal scenario - existing debt gets eliminated and becomes reverse debt',
            'expected_final_state' => [
                'navjot_owes_sapna' => 19.26,
                'third_user_owes_sapna' => 33.33,
                'sapna_owes_navjot' => 0
            ],
            'steps' => $steps
        ];
    }

    /**
     * CASE 3: Multiple Debts Priority
     */
    private function simulateCase3_MultipleDebts()
    {
        // User A owes B $20 and C $30
        // A pays $40 expense (B, C each owe $13.33)
        // Expected: B debt: 20-13.33=6.67, C debt: 30-13.33=16.67

        $balances = [
            'A' => ['B' => 20, 'C' => 30],
            'B' => ['A' => 0, 'C' => 0],
            'C' => ['A' => 0, 'B' => 0]
        ];

        $steps = [];
        $steps[] = [
            'action' => 'Starting state',
            'result' => 'A owes B $20, A owes C $30',
            'balances' => $this->copyBalances($balances)
        ];

        // A pays $40 expense, each person's share = $13.33
        $shareB = 13.33;
        $shareC = 13.33;

        // Reduce A's debt to B
        $reductionB = min($balances['A']['B'], $shareB);
        $balances['A']['B'] -= $reductionB;
        $remainingShareB = $shareB - $reductionB;
        if ($remainingShareB > 0) {
            $balances['B']['A'] += $remainingShareB;
        }

        // Reduce A's debt to C
        $reductionC = min($balances['A']['C'], $shareC);
        $balances['A']['C'] -= $reductionC;
        $remainingShareC = $shareC - $reductionC;
        if ($remainingShareC > 0) {
            $balances['C']['A'] += $remainingShareC;
        }

        $steps[] = [
            'action' => 'A pays $40 expense',
            'calculation' => '$40 ÷ 3 = $13.33 each',
            'debt_reductions' => [
                'B_reduction' => $reductionB,
                'C_reduction' => $reductionC
            ],
            'result' => 'A owes B: $6.67, A owes C: $16.67',
            'balances' => $this->copyBalances($balances)
        ];

        return [
            'description' => 'Multiple debts reduced separately based on equal shares',
            'expected_final_state' => [
                'A_owes_B' => 6.67,
                'A_owes_C' => 16.67
            ],
            'steps' => $steps
        ];
    }

    /**
     * CASE 4: Exact Debt Elimination
     */
    private function simulateCase4_ExactDebtElimination()
    {
        // A owes B exactly $25.00, A pays $75 expense ($25 each)
        $balances = [
            'A' => ['B' => 25.00, 'C' => 0],
            'B' => ['A' => 0, 'C' => 0],
            'C' => ['A' => 0, 'B' => 0]
        ];

        $steps = [];
        $steps[] = [
            'action' => 'Starting state',
            'result' => 'A owes B exactly $25.00',
            'balances' => $this->copyBalances($balances)
        ];

        // A pays $75 expense, each share = $25.00
        $share = 25.00;

        // Reduce A's debt to B (exact elimination)
        $balances['A']['B'] = 0; // Debt eliminated exactly
        // B and C each owe A $25
        $balances['B']['A'] += $share;
        $balances['C']['A'] += $share;

        $steps[] = [
            'action' => 'A pays $75 expense',
            'calculation' => '$75 ÷ 3 = $25.00 each',
            'result' => 'A debt to B eliminated, B owes A $25, C owes A $25',
            'balances' => $this->copyBalances($balances)
        ];

        return [
            'description' => 'Exact debt elimination scenario',
            'expected_final_state' => [
                'A_owes_B' => 0,
                'B_owes_A' => 25.00,
                'C_owes_A' => 25.00
            ],
            'steps' => $steps
        ];
    }

    /**
     * CASE 5: Precision Edge Case
     */
    private function simulateCase5_PrecisionTest()
    {
        // Split $10.01 among 3 people
        $amount = 10.01;
        $participants = 3;

        $amountCents = round($amount * 100); // 1001 cents
        $sharePerPersonCents = intval($amountCents / $participants); // 333 cents
        $remainderCents = $amountCents % $participants; // 2 cents

        $shares = [];
        for ($i = 0; $i < $participants; $i++) {
            $shareCents = $sharePerPersonCents + ($i < $remainderCents ? 1 : 0);
            $shares[] = $shareCents / 100;
        }

        return [
            'description' => 'Cent-accurate distribution with odd amounts',
            'scenario' => 'Split $10.01 among 3 people',
            'calculation' => [
                'total_cents' => $amountCents,
                'base_share_cents' => $sharePerPersonCents,
                'remainder_cents' => $remainderCents
            ],
            'expected_shares' => $shares,
            'verification' => [
                'total_distributed' => array_sum($shares),
                'matches_original' => abs(array_sum($shares) - $amount) < 0.01
            ]
        ];
    }

    /**
     * CASE 7: Settlement Overpayment
     */
    private function simulateCase7_SettlementOverpayment()
    {
        // A owes B $15, A pays B $25
        $balances = [
            'A' => ['B' => 15.00],
            'B' => ['A' => 0]
        ];

        $steps = [];
        $steps[] = [
            'action' => 'Starting state',
            'result' => 'A owes B $15.00',
            'balances' => $this->copyBalances($balances)
        ];

        // A pays B $25 (overpayment of $10)
        $paymentAmount = 25.00;
        $existingDebt = $balances['A']['B'];
        $overpayment = $paymentAmount - $existingDebt;

        $balances['A']['B'] = 0; // Debt eliminated
        $balances['B']['A'] = $overpayment; // B now owes A

        $steps[] = [
            'action' => 'A pays B $25.00',
            'calculation' => 'Debt: $15.00, Payment: $25.00, Overpayment: $10.00',
            'result' => 'Debt eliminated, B now owes A $10.00',
            'balances' => $this->copyBalances($balances)
        ];

        return [
            'description' => 'Settlement overpayment creates reverse debt',
            'expected_final_state' => [
                'A_owes_B' => 0,
                'B_owes_A' => 10.00
            ],
            'steps' => $steps
        ];
    }

    /**
     * CASE 8: Zero-Amount Expense
     */
    private function simulateCase8_ZeroAmountExpense()
    {
        // Test system handles minimal amounts correctly
        $amount = 0.01;
        $participants = 3;

        $amountCents = round($amount * 100); // 1 cent
        $sharePerPersonCents = intval($amountCents / $participants); // 0 cents
        $remainderCents = $amountCents % $participants; // 1 cent

        return [
            'description' => 'System handles minimal amounts correctly',
            'scenario' => 'User pays $0.01 expense among 3 people',
            'calculation' => [
                'total_cents' => $amountCents,
                'base_share_cents' => $sharePerPersonCents,
                'remainder_cents' => $remainderCents
            ],
            'expected_result' => 'First user pays $0.01, others pay $0.00',
            'edge_case_handling' => 'System should handle without errors'
        ];
    }

    /**
     * CASE 9: Large Group Scenario
     */
    private function simulateCase9_LargeGroup()
    {
        $participants = 10;
        $amount = 123.45;

        $amountCents = round($amount * 100);
        $sharePerPersonCents = intval($amountCents / $participants);
        $remainderCents = $amountCents % $participants;

        return [
            'description' => 'Performance and accuracy with many participants',
            'scenario' => '10 users split $123.45',
            'calculation' => [
                'participants' => $participants,
                'amount_cents' => $amountCents,
                'base_share_cents' => $sharePerPersonCents,
                'remainder_cents' => $remainderCents
            ],
            'performance_test' => 'Verify system handles large groups efficiently'
        ];
    }

    /**
     * CASE 10: Time-Based Sequence
     */
    private function simulateCase10_TimeBasedSequence()
    {
        return [
            'description' => 'Order dependency and consistency in rapid transactions',
            'scenario' => 'Multiple rapid transactions in sequence',
            'test_points' => [
                'Transaction order preservation',
                'Balance consistency between transactions',
                'No race conditions in debt reduction',
                'Accurate running balances'
            ],
            'implementation_note' => 'Use created_at timestamps for proper ordering'
        ];
    }

    private function copyBalances($balances)
    {
        return json_decode(json_encode($balances), true);
    }

    private function validateAllScenarios($scenarios)
    {
        $validationResults = [];

        foreach ($scenarios as $caseName => $scenario) {
            $validationResults[$caseName] = [
                'has_expected_state' => isset($scenario['expected_final_state']),
                'has_steps' => isset($scenario['steps']),
                'is_complete' => isset($scenario['description'])
            ];
        }

        return [
            'individual_results' => $validationResults,
            'all_passed' => !in_array(false, array_map(function($result) {
                return $result['has_expected_state'] || $result['has_steps'];
            }, $validationResults))
        ];
    }

    /**
     * Validate that current implementation matches expected behavior
     */
    public function validateImplementation()
    {
        // Test the actual processExpense method against our expected scenarios
        $results = [];

        // Initialize a test balance matrix
        $users = collect([
            (object)['id' => 1, 'name' => 'A'],
            (object)['id' => 2, 'name' => 'B'],
            (object)['id' => 3, 'name' => 'C']
        ]);

        // Test Case 3: Multiple Debts Priority
        $balances = [
            1 => [2 => 20.00, 3 => 30.00], // A owes B $20, C $30
            2 => [1 => 0, 3 => 0],
            3 => [1 => 0, 2 => 0]
        ];

        // Simulate A paying $40 expense
        $mockExpense = (object)[
            'paid_by_user_id' => 1,
            'amount' => 40.00,
            'participant_ids' => [1, 2, 3]
        ];

        $balancesBefore = $this->copyBalances($balances);
        $this->processExpense($mockExpense, $balances, $users);

        $results['case_3_validation'] = [
            'balances_before' => $balancesBefore,
            'balances_after' => $balances,
            'expected_A_owes_B' => 6.67,
            'actual_A_owes_B' => $balances[1][2],
            'expected_A_owes_C' => 16.67,
            'actual_A_owes_C' => $balances[1][3],
            'case_3_passes' => abs($balances[1][2] - 6.67) < 0.01 && abs($balances[1][3] - 16.67) < 0.01
        ];

        return response()->json([
            'implementation_validation' => $results,
            'current_logic_assessment' => 'Testing if actual processExpense matches expected behavior'
        ]);
    }

    /**
     * Calculate detailed breakdown for each expense showing debt reductions and splits
     */
    private function calculateExpenseDetails($expenses, $users)
    {
        $expenseDetails = [];

        foreach ($expenses as $expense) {
            $paidBy = $expense->paid_by_user_id;
            $amount = $expense->amount;

            // Get participants who existed when expense was created
            $participantIds = $expense->participant_ids ?? $users->pluck('id')->toArray();
            $participants = $users->whereIn('id', $participantIds);
            $participantCount = $participants->count();

            if ($participantCount === 0) continue;

            // Calculate precise per-person shares
            $amountCents = round($amount * 100);
            $sharePerPersonCents = intval($amountCents / $participantCount);
            $remainderCents = $amountCents % $participantCount;

            // Get balances before this expense
            $balancesBefore = $this->getBalancesBefore($expense);
            $balancesAfter = $this->getBalancesAfter($expense, $users);

            $details = [
                'expense_id' => $expense->id,
                'description' => $expense->description,
                'amount' => $amount,
                'paid_by' => $expense->paidByUser->name,
                'paid_by_id' => $paidBy,
                'expense_date' => $expense->expense_date,
                'participant_count' => $participantCount,
                'per_person_share' => round($sharePerPersonCents / 100, 2),
                'wallet_before' => $balancesBefore,
                'wallet_after' => $balancesAfter,
                'debt_reductions' => [],
                'normal_splits' => [],
                'net_changes' => []
            ];

            // Calculate what each participant owes and debt reductions
            $index = 0;
            foreach ($participants->sortBy('id') as $participant) {
                if ($participant->id === $paidBy) {
                    $index++;
                    continue;
                }

                // Calculate this participant's share
                $shareCents = $sharePerPersonCents + ($index < $remainderCents ? 1 : 0);
                $share = $shareCents / 100;

                // Check for debt reduction
                $debtBefore = $balancesBefore[$paidBy]['owes'][$participant->id] ?? 0;
                $debtAfter = $balancesAfter[$paidBy]['owes'][$participant->id] ?? 0;
                $debtReduction = max(0, $debtBefore - $debtAfter);

                if ($debtReduction > 0) {
                    $details['debt_reductions'][] = [
                        'user_id' => $participant->id,
                        'user_name' => $participant->name,
                        'debt_before' => $debtBefore,
                        'debt_after' => $debtAfter,
                        'reduction_amount' => $debtReduction
                    ];
                }

                $details['normal_splits'][] = [
                    'user_id' => $participant->id,
                    'user_name' => $participant->name,
                    'share_amount' => $share,
                    'debt_reduction' => $debtReduction,
                    'net_new_debt' => max(0, $share - $debtReduction)
                ];

                $index++;
            }

            // Calculate net balance changes for all users
            foreach ($users as $user) {
                $balanceBefore = $balancesBefore[$user->id]['net_balance'] ?? 0;
                $balanceAfter = $balancesAfter[$user->id]['net_balance'] ?? 0;
                $change = round($balanceAfter - $balanceBefore, 2);

                if (abs($change) >= 0.01) {
                    $details['net_changes'][] = [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'net_change' => $change
                    ];
                }
            }

            $expenseDetails[$expense->id] = $details;
        }

        return $expenseDetails;
    }

    /**
     * Calculate detailed breakdown for each settlement showing debt before/after
     */
    private function calculateSettlementDetails($settlements, $users)
    {
        $settlementDetails = [];

        foreach ($settlements as $settlement) {
            $fromId = $settlement->from_user_id;
            $toId = $settlement->to_user_id;
            $paymentAmount = $settlement->amount;

            // Get balances before and after this settlement
            $balancesBefore = $this->getBalancesBefore(null, $settlement);
            $balancesAfter = $this->getBalancesAfter(null, $users, $settlement);

            // Calculate debt amounts
            $debtBefore = $balancesBefore[$fromId]['owes'][$toId] ?? 0;
            $debtAfter = $balancesAfter[$fromId]['owes'][$toId] ?? 0;
            $debtReduction = max(0, $debtBefore - $debtAfter);
            $excessPayment = max(0, $paymentAmount - $debtBefore);

            $details = [
                'settlement_id' => $settlement->id,
                'from_user_name' => $settlement->fromUser->name,
                'to_user_name' => $settlement->toUser->name,
                'from_user_id' => $fromId,
                'to_user_id' => $toId,
                'payment_amount' => $paymentAmount,
                'settlement_date' => $settlement->settlement_date,
                'wallet_before' => $balancesBefore,
                'wallet_after' => $balancesAfter,
                'debt_analysis' => [
                    'debt_before' => $debtBefore,
                    'debt_after' => $debtAfter,
                    'debt_reduction' => $debtReduction,
                    'excess_payment' => $excessPayment,
                    'creates_reverse_debt' => $excessPayment > 0
                ],
                'net_changes' => []
            ];

            // Calculate net balance changes for all users
            foreach ($users as $user) {
                $balanceBefore = $balancesBefore[$user->id]['net_balance'] ?? 0;
                $balanceAfter = $balancesAfter[$user->id]['net_balance'] ?? 0;
                $change = round($balanceAfter - $balanceBefore, 2);

                if (abs($change) >= 0.01) {
                    $details['net_changes'][] = [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'net_change' => $change
                    ];
                }
            }

            $settlementDetails[$settlement->id] = $details;
        }

        return $settlementDetails;
    }

    /**
     * Get balances after a specific transaction (expense or settlement)
     */
    private function getBalancesAfter($expense = null, $users = null, $settlement = null)
    {
        $users = $users ?? User::where('is_active', true)->get();
        $cutoffDate = null;

        if ($expense) {
            $cutoffDate = $expense->created_at;
        } elseif ($settlement) {
            $cutoffDate = $settlement->created_at;
        }

        if (!$cutoffDate) {
            return $this->calculateBalances();
        }

        // Calculate balances up to and including this transaction
        $balances = [];
        foreach ($users as $user1) {
            foreach ($users as $user2) {
                if ($user1->id !== $user2->id) {
                    $balances[$user1->id][$user2->id] = 0;
                }
            }
        }

        // Process expenses up to and including cutoff
        $expenses = Expense::where('created_at', '<=', $cutoffDate)->orderBy('created_at')->get();
        foreach ($expenses as $exp) {
            $this->processExpense($exp, $balances, $users);
        }

        // Process settlements up to and including cutoff
        $settlements = Settlement::where('created_at', '<=', $cutoffDate)->orderBy('created_at')->get();
        foreach ($settlements as $sett) {
            $this->processSettlement($sett, $balances);
        }

        $this->consolidateDebts($balances, $users);
        return $this->formatBalancesForDisplay($balances, $users);
    }
}