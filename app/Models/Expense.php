<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'is_payback' => 'boolean',
        'payback_amount' => 'decimal:2',
    ];

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function paybackToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payback_to_user_id');
    }

    public function getAmountPerPersonAttribute()
    {
        return $this->amount / 3;
    }
}
