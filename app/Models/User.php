<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'google_id',
        'apple_id',
        'password',
        'is_active',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'paid_by_user_id');
    }

    public function settlementsGiven(): HasMany
    {
        return $this->hasMany(Settlement::class, 'from_user_id');
    }

    public function settlementsReceived(): HasMany
    {
        return $this->hasMany(Settlement::class, 'to_user_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_user')
            ->withPivot('role', 'is_active', 'expense_email_notifications', 'settlement_email_notifications', 'joined_at')
            ->withTimestamps();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
