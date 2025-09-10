<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    protected $fillable = [
        'description',
        'amount',
        'paid_by_user_id',
        'receipt_photo',
        'expense_date',
        'is_payback',
        'payback_to_user_id',
        'payback_amount',
        'user_count_at_time',
        'participant_ids',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'is_payback' => 'boolean',
        'payback_amount' => 'decimal:2',
        'participant_ids' => 'array',
    ];

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function paybackToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payback_to_user_id');
    }

    public function paybacks(): HasMany
    {
        return $this->hasMany(ExpensePayback::class);
    }

    public function getAmountPerPersonAttribute()
    {
        // Use the user count that existed when this expense was created
        $totalUsers = $this->user_count_at_time ?? User::count();
        return $totalUsers > 0 ? $this->amount / $totalUsers : $this->amount;
    }
}
