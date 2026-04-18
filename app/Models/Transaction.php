<?php

// app/Models/Transaction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'type',
        'amount',
        'reference',
        'status',
        'provider',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta'   => 'array',
    ];

    // ── Auto-generate reference on creation ────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Transaction $tx) {
            if (empty($tx->reference)) {
                $tx->reference = self::generateReference($tx->type ?? 'txn');
            }
        });
    }

    public static function generateReference(string $prefix = 'TXN'): string
    {
        $prefix = strtoupper(substr($prefix, 0, 3));
        return $prefix . '-' . strtoupper(Str::ulid());
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }
}