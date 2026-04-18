<?php

// app/Models/Wallet.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'balance',
        'status',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    // ── Relations ──────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // ── Ledger Mutations (called ONLY from WebhookController) ──────────────────

    /**
     * Credit the ledger. Must be called inside a DB transaction with a
     * row-level lock (lockForUpdate) to prevent race conditions.
     */
    public function credit(float $amount): void
    {
        // increment() issues a single UPDATE ... SET balance = balance + ?
        // which is atomic at the database level.
        $this->increment('balance', $amount);
    }

    /**
     * Debit the ledger. Throws if funds are insufficient.
     * Must be called inside a DB transaction with lockForUpdate.
     */
    public function debit(float $amount): void
    {
        if (bccomp((string) $this->balance, (string) $amount, 2) < 0) {
            throw new \DomainException('Insufficient wallet balance.');
        }

        $this->decrement('balance', $amount);
    }
}