<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceState extends Model
{
    protected $fillable = [
        'expense_id',
        'settlement_id',
        'user_balances',
        'transaction_date',
    ];

    protected $casts = [
        'user_balances' => 'array',
        'transaction_date' => 'datetime',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}