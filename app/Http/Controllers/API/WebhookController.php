<?php

// app/Http/Controllers/Api/WebhookController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\LencoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController
 * ─────────────────
 * This is THE ONLY place in the entire application where wallet.balance
 * is modified in response to confirmed external events from Lenco.
 *
 * SECURITY RULES (all enforced here):
 *   1. Every inbound request is signature-verified before any DB work.
 *   2. All DB mutations are wrapped in transactions with row-level locks.
 *   3. Each reference is processed exactly once (idempotency guard).
 *   4. We ALWAYS return HTTP 200 to Lenco — this stops endless retry loops
 *      for events we intentionally ignore (duplicates, unknowns).
 *
 * EVENT MAP:
 *   collection.successful  → credit wallet, mark transaction success
 *   collection.failed      → mark transaction failed (no balance change)
 *   transfer.successful    → mark transaction success (balance already debited)
 *   transfer.failed        → mark transaction failed, refund wallet balance
 */
class WebhookController extends Controller
{
    public function __construct(private readonly LencoService $lenco) {}

    // ─────────────────────────────────────────────────────────────────────────
    // POST /webhook/lenco  (public, no Sanctum)
    // ─────────────────────────────────────────────────────────────────────────

    public function handle(Request $request): JsonResponse
    {

        Log::info('[Webhook] Received request', [
            'ip' => $request->ip(),
            'headers' => $request->headers->all(),
        ]);

        $rawBody   = $request->getContent();
        $signature = $request->header('X-Lenco-Signature', '');

        // ── GUARD 1: Verify signature ──────────────────────────────────────
        if (!$this->lenco->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('[Webhook] Invalid signature', [
                'ip'        => $request->ip(),
                'signature' => substr($signature, 0, 20) . '…',
            ]);

            // Return 401 for invalid signatures — this IS an error we want Lenco
            // to know about, unlike duplicates which get a silent 200.
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload   = $request->json()->all();
        $event     = $payload['event']      ?? 'unknown';
        $data      = $payload['data']       ?? [];
        $reference = $data['reference']     ?? null;

        Log::info('[Webhook] Received event', [
            'event'     => $event,
            'reference' => $reference,
        ]);

        // ── GUARD 2: Reference must be present ────────────────────────────
        if (!$reference) {
            Log::warning('[Webhook] Missing reference in payload', ['event' => $event]);
            return response()->json(['message' => 'ok'], 200);
        }

        // ── Route to handler ──────────────────────────────────────────────
        match ($event) {
            'collection.successful' => $this->onCollectionSuccessful($reference, $data),
            'collection.failed'     => $this->onCollectionFailed($reference, $data),
            'transfer.successful'   => $this->onTransferSuccessful($reference, $data),
            'transfer.failed'       => $this->onTransferFailed($reference, $data),
            default => Log::info('[Webhook] Unhandled event ignored', ['event' => $event]),
        };

        // Always 200 — Lenco should not retry processed or unknown events.
        return response()->json(['message' => 'ok'], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Event Handlers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A deposit was confirmed by Lenco.
     *
     * ONLY now do we credit the wallet. Doing it earlier (e.g. in the deposit
     * controller) would mean trusting unverified frontend/API calls.
     */
    private function onCollectionSuccessful(string $reference, array $data): void
    {
        DB::transaction(function () use ($reference, $data) {

            // Lock the transaction row for the duration of this DB transaction
            $transaction = Transaction::where('reference', $reference)
                ->where('type', 'deposit')
                ->lockForUpdate()
                ->first();

            // ── GUARD 3: Idempotency — skip already-processed events ───────
            if (!$transaction || $transaction->status !== 'pending') {
                Log::info('[Webhook] collection.successful skipped (not pending or not found)', [
                    'reference' => $reference,
                    'status'    => $transaction?->status ?? 'not_found',
                ]);
                return;
            }

            // ── GUARD 4: Amount integrity ──────────────────────────────────
            // Lenco sends amount in minor units; convert back to major.
            $lencoAmount = isset($data['amount'])
                ? round((float) $data['amount'] / 100, 2)
                : null;

            if ($lencoAmount !== null && bccomp((string) $lencoAmount, (string) $transaction->amount, 2) !== 0) {
                Log::critical('[Webhook] Amount mismatch on collection!', [
                    'reference'    => $reference,
                    'expected'     => $transaction->amount,
                    'lenco_amount' => $lencoAmount,
                ]);
                // Flag but still credit the ACTUAL received amount and alert ops.
                // Adjust policy to your compliance requirements.
            }

            $amountToCredit = $lencoAmount ?? (float) $transaction->amount;

            // Credit the wallet (atomic increment at DB level)
            $transaction->wallet()->lockForUpdate()->first()->credit($amountToCredit);

            // Mark transaction success
            $transaction->update([
                'status' => 'success',
                'meta'   => array_merge($transaction->meta ?? [], [
                    'webhook_event'     => 'collection.successful',
                    'lenco_data'        => $data,
                    'confirmed_at'      => now()->toIso8601String(),
                    'amount_confirmed'  => $amountToCredit,
                ]),
            ]);

            Log::info('[Webhook] Deposit credited to wallet', [
                'reference' => $reference,
                'wallet_id' => $transaction->wallet_id,
                'amount'    => $amountToCredit,
            ]);
        });
    }

    /**
     * A deposit failed (e.g. user abandoned payment, bank declined).
     * No balance change — just record the outcome.
     */
    private function onCollectionFailed(string $reference, array $data): void
    {
        DB::transaction(function () use ($reference, $data) {

            $transaction = Transaction::where('reference', $reference)
                ->where('type', 'deposit')
                ->lockForUpdate()
                ->first();

            if (!$transaction || $transaction->status !== 'pending') {
                Log::info('[Webhook] collection.failed skipped', ['reference' => $reference]);
                return;
            }

            $transaction->update([
                'status' => 'failed',
                'meta'   => array_merge($transaction->meta ?? [], [
                    'webhook_event' => 'collection.failed',
                    'lenco_data'    => $data,
                    'failed_at'     => now()->toIso8601String(),
                ]),
            ]);

            Log::info('[Webhook] Deposit marked failed', ['reference' => $reference]);
        });
    }

    /**
     * A withdrawal was successfully sent to the recipient.
     * Balance was already debited in WalletController — nothing to change.
     */
    private function onTransferSuccessful(string $reference, array $data): void
    {
        DB::transaction(function () use ($reference, $data) {

            $transaction = Transaction::where('reference', $reference)
                ->where('type', 'withdrawal')
                ->lockForUpdate()
                ->first();

            if (!$transaction || $transaction->status !== 'pending') {
                Log::info('[Webhook] transfer.successful skipped', ['reference' => $reference]);
                return;
            }

            // Balance already debited. Just confirm the record.
            $transaction->update([
                'status' => 'success',
                'meta'   => array_merge($transaction->meta ?? [], [
                    'webhook_event' => 'transfer.successful',
                    'lenco_data'    => $data,
                    'confirmed_at'  => now()->toIso8601String(),
                ]),
            ]);

            Log::info('[Webhook] Withdrawal confirmed', ['reference' => $reference]);
        });
    }

    /**
     * A withdrawal failed (bank rejected, network error, etc.).
     * Refund the user's wallet balance — the debit in WalletController must
     * be reversed because the money never left.
     */
    private function onTransferFailed(string $reference, array $data): void
    {
        DB::transaction(function () use ($reference, $data) {

            $transaction = Transaction::where('reference', $reference)
                ->where('type', 'withdrawal')
                ->lockForUpdate()
                ->first();

            if (!$transaction || $transaction->status !== 'pending') {
                Log::info('[Webhook] transfer.failed skipped (already handled)', [
                    'reference' => $reference,
                    'status'    => $transaction?->status ?? 'not_found',
                ]);
                return;
            }

            // Credit the wallet back — reverse the earlier debit
            $transaction->wallet()->lockForUpdate()->first()->credit($transaction->amount);

            $transaction->update([
                'status' => 'failed',
                'meta'   => array_merge($transaction->meta ?? [], [
                    'webhook_event' => 'transfer.failed',
                    'lenco_data'    => $data,
                    'refunded_at'   => now()->toIso8601String(),
                ]),
            ]);

            Log::info('[Webhook] Withdrawal failed — balance refunded', [
                'reference' => $reference,
                'wallet_id' => $transaction->wallet_id,
                'refunded'  => $transaction->amount,
            ]);
        });
    }
}