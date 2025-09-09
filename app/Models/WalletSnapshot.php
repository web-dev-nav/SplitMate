<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletSnapshot extends Model
{
    protected $fillable = [
        'expense_id',
        'settlement_id',
        'user_id',
        'net_balance',
        'owes_details',
        'receives_details',
        'snapshot_date',
    ];

    protected $casts = [
        'owes_details' => 'array',
        'receives_details' => 'array',
        'snapshot_date' => 'datetime',
        'net_balance' => 'decimal:2',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
