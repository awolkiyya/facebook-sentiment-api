<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMfaMethod extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'user_mfa_methods';

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'secret',
        'confirmed_at',
        'last_used_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            /*
             * Laravel automatically encrypts the secret before storing it
             * and decrypts it when accessed through the model.
             */
            'secret' => 'encrypted',

            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | State Helpers
    |--------------------------------------------------------------------------
    */

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isEnabled(): bool
    {
        return $this->is_active && $this->isConfirmed();
    }

    public function isTotp(): bool
    {
        return strtoupper($this->type) === 'TOTP';
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    public function markAsConfirmed(): void
    {
        $this->update([
            'confirmed_at' => now(),
            'is_active' => true,
        ]);
    }

    public function markAsUsed(): void
    {
        $this->update([
            'last_used_at' => now(),
        ]);
    }

    public function deactivate(): void
    {
        $this->update([
            'is_active' => false,
        ]);
    }
}