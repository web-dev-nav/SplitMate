<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ExpenseCreatedMail;
use App\Models\AdminSetting;
use App\Models\Expense;
use App\Models\Group;
use App\Models\StatementRecord;
use App\Models\User;
use App\Services\BalanceService;
use App\Support\ApiPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    public function __construct(private BalanceService $balanceService)
    {
    }

    /**
     * List all expenses in a group.
     */
    public function index(Request $request, Group $group)
    {
        $expenses = $group->expenses()
            ->with('paidByUser')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'expenses' => collect($expenses->items())->map(fn (Expense $expense) => ApiPayload::expense($expense))->values(),
            'pagination' => [
                'total' => $expenses->total(),
                'per_page' => $expenses->perPage(),
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
            ],
        ]);
    }

    /**
     * Create a new expense.
     */
    public function store(Request $request, Group $group)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'amount_cents' => 'required|integer|min:1',
            'paid_by_user_id' => 'required|string|exists:users,uuid',
            'expense_date' => 'required|date',
            'category' => 'required|string|max:50',
            'participant_ids' => 'nullable|array',
            'participant_ids.*' => 'string|exists:users,uuid',
        ]);

        $activeMemberUuids = $group->members()
            ->wherePivot('is_active', true)
            ->pluck('uuid')
            ->toArray();

        $category = strtolower(trim($validated['category']));
        $allowedCategories = $group->expense_categories ?? Group::defaultExpenseCategories();
        if (!in_array($category, $allowedCategories, true)) {
            return response()->json([
                'message' => 'The selected category is invalid for this group.',
                'errors' => [
                    'category' => ['The selected category is invalid for this group.'],
                ],
            ], 422);
        }

        // Convert user UUIDs to user IDs
        $paidByUser = \App\Models\User::where('uuid', $validated['paid_by_user_id'])->firstOrFail();
        if (!in_array($paidByUser->uuid, $activeMemberUuids, true)) {
            return response()->json([
                'message' => 'Payer must be an active group member.',
                'errors' => [
                    'paid_by_user_id' => ['Payer must be an active group member.'],
                ],
            ], 422);
        }

        // Get active group members if participants not specified
        if (empty($validated['participant_ids'])) {
            $participantIds = $activeMemberUuids;
        } else {
            $participantIds = array_values(array_unique($validated['participant_ids']));
            $invalidParticipants = array_values(array_diff($participantIds, $activeMemberUuids));
            if (!empty($invalidParticipants)) {
                return response()->json([
                    'message' => 'All participants must be active members of this group.',
                    'errors' => [
                        'participant_ids' => ['All participants must be active members of this group.'],
                    ],
                ], 422);
            }
        }

        // Create expense + statement records atomically.
        $expense = DB::transaction(function () use ($group, $validated, $paidByUser, $category, $participantIds, $activeMemberUuids) {
            $expense = Expense::create([
                'uuid' => Str::uuid(),
                'group_id' => $group->id,
                'title' => $validated['title'],
                // Backward compatibility for legacy schema where description/amount are required.
                'description' => $validated['title'],
                'amount_cents' => $validated['amount_cents'],
                'amount' => round($validated['amount_cents'] / 100, 2),
                'paid_by_user_id' => $paidByUser->id,
                'expense_date' => $validated['expense_date'],
                'category' => $category,
                'participant_ids' => $participantIds,
                'user_count_at_time' => count($activeMemberUuids),
            ]);

            $this->balanceService->createStatementRecords($group, expense: $expense);

            return $expense;
        });
        $expense->load('paidByUser');

        // Send email notifications to all active group members with email addresses.
        $this->sendExpenseNotifications($group, $expense, $paidByUser);

        return response()->json([
            'expense' => ApiPayload::expense($expense),
        ], 201);
    }

    /**
     * Get expense details.
     */
    public function show(Request $request, Group $group, Expense $expense)
    {
        if ($expense->group_id !== $group->id) {
            return response()->json(['message' => 'Expense not found in this group'], 404);
        }

        return response()->json([
            'expense' => ApiPayload::expense($expense->load('paidByUser')),
        ]);
    }

    /**
     * Update an expense record (owner only).
     */
    public function update(Request $request, Group $group, Expense $expense)
    {
        if ($expense->group_id !== $group->id) {
            return response()->json(['message' => 'Expense not found in this group'], 404);
        }

        $isGroupOwner = $this->isGroupOwner($group, $request->user()->id);
        if (!$isGroupOwner && (int) $expense->paid_by_user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Only the member who added this expense or the group owner can edit it.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'amount_cents' => 'required|integer|min:1',
            'paid_by_user_id' => 'required|string|exists:users,uuid',
            'expense_date' => 'required|date',
            'category' => 'required|string|max:50',
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'string|exists:users,uuid',
        ]);

        if (!$isGroupOwner && $validated['paid_by_user_id'] !== (string) $request->user()->uuid) {
            return response()->json([
                'message' => 'You can only edit expenses you paid from your own account unless you are the group owner.',
            ], 422);
        }

        $activeMemberUuids = $group->members()
            ->wherePivot('is_active', true)
            ->pluck('uuid')
            ->toArray();

        $category = strtolower(trim($validated['category']));
        $allowedCategories = $group->expense_categories ?? Group::defaultExpenseCategories();
        if (!in_array($category, $allowedCategories, true)) {
            return response()->json([
                'message' => 'The selected category is invalid for this group.',
                'errors' => [
                    'category' => ['The selected category is invalid for this group.'],
                ],
            ], 422);
        }

        $participantIds = array_values(array_unique($validated['participant_ids']));
        $invalidParticipants = array_values(array_diff($participantIds, $activeMemberUuids));
        if (!empty($invalidParticipants)) {
            return response()->json([
                'message' => 'All participants must be active members of this group.',
                'errors' => [
                    'participant_ids' => ['All participants must be active members of this group.'],
                ],
            ], 422);
        }

        $updated = DB::transaction(function () use ($expense, $group, $validated, $category, $participantIds, $activeMemberUuids) {
            StatementRecord::where('expense_id', $expense->id)->delete();

            $expense->update([
                'title' => $validated['title'],
                'description' => $validated['title'],
                'amount_cents' => $validated['amount_cents'],
                'amount' => round($validated['amount_cents'] / 100, 2),
                'expense_date' => $validated['expense_date'],
                'category' => $category,
                'participant_ids' => $participantIds,
                'user_count_at_time' => count($activeMemberUuids),
            ]);

            $this->balanceService->createStatementRecords($group, expense: $expense->fresh());

            return $expense->fresh();
        });

        $updated->load('paidByUser');

        return response()->json([
            'expense' => ApiPayload::expense($updated),
        ]);
    }

    /**
     * Update participants for an expense.
     */
    public function updateParticipants(Request $request, Group $group, Expense $expense)
    {
        if ($expense->group_id !== $group->id) {
            return response()->json(['message' => 'Expense not found in this group'], 404);
        }

        if (
            !$this->isGroupOwner($group, $request->user()->id) &&
            (int) $expense->paid_by_user_id !== (int) $request->user()->id
        ) {
            return response()->json([
                'message' => 'Only the member who added this expense or the group owner can edit it.',
            ], 403);
        }

        $validated = $request->validate([
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'string|exists:users,uuid',
        ]);

        $activeMemberUuids = $group->members()
            ->wherePivot('is_active', true)
            ->pluck('uuid')
            ->toArray();
        $participantIds = array_values(array_unique($validated['participant_ids']));
        $invalidParticipants = array_values(array_diff($participantIds, $activeMemberUuids));
        if (!empty($invalidParticipants)) {
            return response()->json([
                'message' => 'All participants must be active members of this group.',
                'errors' => [
                    'participant_ids' => ['All participants must be active members of this group.'],
                ],
            ], 422);
        }

        $expense = DB::transaction(function () use ($expense, $group, $participantIds) {
            StatementRecord::where('expense_id', $expense->id)->delete();

            $expense->update([
                'participant_ids' => $participantIds,
            ]);

            $this->balanceService->createStatementRecords($group, expense: $expense->fresh());

            return $expense->fresh();
        });

        return response()->json([
            'expense' => ApiPayload::expense($expense->load('paidByUser')),
        ]);
    }

    /**
     * Upload receipt image.
     */
    public function uploadReceipt(Request $request, Group $group, Expense $expense)
    {
        if ($expense->group_id !== $group->id) {
            return response()->json(['message' => 'Expense not found in this group'], 404);
        }

        if (
            !$this->isGroupOwner($group, $request->user()->id) &&
            (int) $expense->paid_by_user_id !== (int) $request->user()->id
        ) {
            return response()->json([
                'message' => 'Only the member who added this expense or the group owner can modify or delete it.',
            ], 403);
        }

        $validated = $request->validate([
            'receipt_photo' => 'required|image|max:15360', // 15MB
        ]);

        try {
            $path = $request->file('receipt_photo')->store('receipts', 'public');
            $expense->update(['receipt_photo' => $path]);

            return response()->json([
                'expense' => ApiPayload::expense($expense->load('paidByUser')),
                'message' => 'Receipt uploaded successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload receipt',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete receipt image.
     */
    public function deleteReceipt(Request $request, Group $group, Expense $expense)
    {
        if ($expense->group_id !== $group->id) {
            return response()->json(['message' => 'Expense not found in this group'], 404);
        }

        if (
            !$this->isGroupOwner($group, $request->user()->id) &&
            (int) $expense->paid_by_user_id !== (int) $request->user()->id
        ) {
            return response()->json([
                'message' => 'Only the member who added this expense or the group owner can modify or delete it.',
            ], 403);
        }

        if ($expense->receipt_photo) {
            Storage::disk('public')->delete($expense->receipt_photo);
        }

        $expense->update(['receipt_photo' => null]);

        return response()->json([
            'expense' => ApiPayload::expense($expense->load('paidByUser')),
            'message' => 'Receipt deleted successfully',
        ]);
    }

    /**
     * Delete an expense record (owner only).
     */
    public function destroy(Request $request, Group $group, Expense $expense)
    {
        if ($expense->group_id !== $group->id) {
            return response()->json(['message' => 'Expense not found in this group'], 404);
        }

        if (
            !$this->isGroupOwner($group, $request->user()->id) &&
            (int) $expense->paid_by_user_id !== (int) $request->user()->id
        ) {
            return response()->json([
                'message' => 'Only the member who added this expense or the group owner can delete it.',
            ], 403);
        }

        DB::transaction(function () use ($expense) {
            StatementRecord::where('expense_id', $expense->id)->delete();

            if ($expense->receipt_photo) {
                Storage::disk('public')->delete($expense->receipt_photo);
            }

            $expense->delete();
        });

        return response()->json([
            'message' => 'Expense deleted successfully.',
        ]);
    }

    private function sendExpenseNotifications(Group $group, Expense $expense, User $paidByUser): void
    {
        if (!$group->email_notifications) {
            Log::info('Expense notification skipped because group email notifications are disabled.', [
                'group_id' => $group->id,
                'expense_id' => $expense->id,
                'paid_by_user_id' => $paidByUser->id,
            ]);
            return;
        }

        AdminSetting::applySmtpSettingsToRuntime();

        $paidByName = optional($expense->paidByUser)->name ?? 'Someone';
        $snapshot = $this->balanceService->calculateSnapshot($group);
        $activeMembers = $group->members()
            ->wherePivot('is_active', true)
            ->wherePivot('expense_email_notifications', true)
            ->whereNotNull('users.email')
            ->get();

        Log::info('Expense notification dispatch starting.', [
            'group_id' => $group->id,
            'expense_id' => $expense->id,
            'paid_by_user_id' => $paidByUser->id,
            'recipient_count' => $activeMembers->count(),
            'mailer' => config('mail.default'),
            'mail_host' => config('mail.mailers.smtp.host'),
            'mail_port' => config('mail.mailers.smtp.port'),
            'mail_scheme' => config('mail.mailers.smtp.scheme'),
            'mail_encryption' => config('mail.mailers.smtp.encryption'),
        ]);

        if ($activeMembers->isEmpty()) {
            Log::warning('Expense notification skipped because no active members with email were found.', [
                'group_id' => $group->id,
                'expense_id' => $expense->id,
            ]);
            return;
        }

        foreach ($activeMembers as $member) {
            try {
                Mail::to($member->email)->send(
                    new ExpenseCreatedMail(
                        $expense,
                        $group,
                        $member,
                        $paidByName,
                        $this->shareForRecipient($expense, $member),
                        $this->snapshotForRecipient($snapshot['summaries'] ?? [], $member)
                    )
                );

                Log::info('Expense notification sent successfully.', [
                    'group_id' => $group->id,
                    'expense_id' => $expense->id,
                    'recipient_user_id' => $member->id,
                    'recipient_email' => $member->email,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send expense notification email.', [
                    'group_id' => $group->id,
                    'expense_id' => $expense->id,
                    'recipient_user_id' => $member->id,
                    'recipient_email' => $member->email,
                    'exception_class' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function shareForRecipient(Expense $expense, User $recipient): int
    {
        $participants = collect($expense->participant_ids ?? [])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->sort()
            ->values();

        if ($participants->isEmpty() || !$participants->contains((string) $recipient->uuid)) {
            return 0;
        }

        $count = $participants->count();
        $baseShare = intdiv((int) $expense->amount_cents, $count);
        $remainder = (int) $expense->amount_cents % $count;
        $index = $participants->search((string) $recipient->uuid);

        return $baseShare + ($index < $remainder ? 1 : 0);
    }

    private function snapshotForRecipient(array $summaries, User $recipient): array
    {
        $summary = $summaries[$recipient->uuid] ?? [
            'user_name' => $recipient->name,
            'owes' => [],
            'owed_by' => [],
            'net_balance_cents' => 0,
        ];

        $nameByUuid = collect($summaries)->mapWithKeys(fn ($item, $uuid) => [$uuid => $item['user_name'] ?? 'Unknown'])->all();
        $owesLines = [];
        foreach (($summary['owes'] ?? []) as $otherUuid => $amount) {
            $owesLines[] = [
                'name' => $nameByUuid[$otherUuid] ?? 'Unknown',
                'amount_cents' => (int) $amount,
            ];
        }

        $owedByLines = [];
        foreach (($summary['owed_by'] ?? []) as $otherUuid => $amount) {
            $owedByLines[] = [
                'name' => $nameByUuid[$otherUuid] ?? 'Unknown',
                'amount_cents' => (int) $amount,
            ];
        }

        usort($owesLines, fn ($a, $b) => $b['amount_cents'] <=> $a['amount_cents']);
        usort($owedByLines, fn ($a, $b) => $b['amount_cents'] <=> $a['amount_cents']);

        return [
            'user_name' => $summary['user_name'] ?? $recipient->name,
            'net_balance_cents' => (int) ($summary['net_balance_cents'] ?? 0),
            'owes_lines' => $owesLines,
            'owed_by_lines' => $owedByLines,
        ];
    }

    private function isGroupOwner(Group $group, int $userId): bool
    {
        return (int) $group->created_by_user_id === $userId;
    }
}
