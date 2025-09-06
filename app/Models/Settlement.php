<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Settlement extends Model
{
    protected $fillable = [
        'from_user_id',
        'to_user_id',
        'amount',
        'settlement_date',
        'payment_screenshot',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
