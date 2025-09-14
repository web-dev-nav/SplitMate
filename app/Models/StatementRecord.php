<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatementRecord extends Model
{
    protected $fillable = [
        'user_id',
        'expense_id',
        'settlement_id',
        'transaction_type',
        'description',
        'amount',
        'reference_number',
        'balance_before',
        'balance_after',
        'balance_change',
        'transaction_details',
        'transaction_date',
        'status'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'balance_change' => 'decimal:2',
        'transaction_details' => 'array',
        'transaction_date' => 'datetime'
    ];

    /**
     * Get the user that owns this statement record
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the expense that created this record (if applicable)
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * Get the settlement that created this record (if applicable)
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    /**
     * Generate a unique reference number for this transaction
     */
    public static function generateReferenceNumber($type = 'TXN'): string
    {
        $date = date('Ymd');

        // Get the count of records for today to create a sequential number
        $todayStart = date('Y-m-d') . ' 00:00:00';
        $todayEnd = date('Y-m-d') . ' 23:59:59';
        $count = static::whereBetween('created_at', [$todayStart, $todayEnd])->count() + 1;
        $sequential = str_pad($count, 3, '0', STR_PAD_LEFT);

        return "{$type}{$date}{$sequential}";
    }

    /**
     * Scope to get records for a specific user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get records by transaction type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('transaction_type', $type);
    }

    /**
     * Scope to get records within date range
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    /**
     * Get formatted balance change with + or - sign
     */
    public function getFormattedBalanceChangeAttribute(): string
    {
        return ($this->balance_change >= 0 ? '+' : '') . '$' . number_format($this->balance_change, 2);
    }

    /**
     * Get formatted running balance with + or - sign
     */
    public function getFormattedBalanceAfterAttribute(): string
    {
        return ($this->balance_after >= 0 ? '+' : '') . '$' . number_format($this->balance_after, 2);
    }
}
