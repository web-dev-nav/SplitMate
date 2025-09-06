<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePayback extends Model
{
    protected $fillable = [
        'expense_id',
        'payback_to_user_id',
        'payback_amount',
    ];

    protected $casts = [
        'payback_amount' => 'decimal:2',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function paybackToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payback_to_user_id');
    }
}