<?php

// app/Http/Controllers/Api/WalletController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\LencoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * WalletController
 * ────────────────
 * Handles deposit (collection) and withdrawal (transfer) initiation.
 *
 * CRITICAL CONTRACT:
 *   This controller NEVER modifies wallet.balance.
 *   Balance changes happen EXCLUSIVELY in WebhookController after
 *   Lenco confirms the event via a signed webhook.
 */
class WalletController extends Controller
{
    public function __construct(private readonly LencoService $lenco) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/wallet
    // ─────────────────────────────────────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->getActiveWallet($request);

        return $this->ok('Wallet retrieved.', [
            'wallet' => [
                'id'         => $wallet->id,
                'balance'    => (float) $wallet->balance,
                'status'     => $wallet->status,
                'updated_at' => $wallet->updated_at,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/wallet/transactions
    // ─────────────────────────────────────────────────────────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $wallet = $this->getActiveWallet($request);

        $transactions = $wallet->transactions()
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->ok('Transactions retrieved.', ['transactions' => $transactions]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/wallet/deposit
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Step 1 of a deposit:
     *   1. Validate the request.
     *   2. Ensure the user has an active wallet (auto-create if missing).
     *   3. Persist a PENDING transaction as the ledger entry.
     *   4. Call Lenco to initialize the collection and get a checkout URL.
     *   5. Store Lenco's response in transaction.meta for auditability.
     *   6. Return the checkout URL to the client.
     *
     * Step 2 happens in WebhookController when Lenco fires collection.successful.
     */
    public function deposit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'phone' => ['required', 'string'],
            'mno' => ['required', 'string']
        ]);

        $user   = $request->user();
        $wallet = $this->resolveWallet($user);

        // Generate reference BEFORE calling Lenco so it exists in our DB first
        $reference = Transaction::generateReference('DEP');

        // ── Step 1: Write the pending transaction ──────────────────────────
        $transaction = Transaction::create([
            'user_id'   => $user->id,
            'wallet_id' => $wallet->id,
            'type'      => 'deposit',
            'amount'    => $data['amount'],
            'reference' => $reference,
            'status'    => 'pending',
            'provider'  => 'lenco',
            'meta'      => ['initiated_at' => now()->toIso8601String()],
        ]);

        // ── Step 2: Call Lenco ─────────────────────────────────────────────
        try {
            $lencoData = $this->lenco->initializeDeposit(
                amount:      (float) $data['amount'],
                reference:   $reference,
                phone:       $data['phone'],
                operator: $data['mno']
                // callbackUrl: config('services.lenco.callback_url'),
            );

            // Persist Lenco's response for full audit trail
            $transaction->update(['meta' => array_merge(
                $transaction->meta ?? [],
                ['lenco_init' => $lencoData]
            )]);

            Log::info('[Wallet] Deposit initialized', [
                'user_id'   => $user->id,
                'reference' => $reference,
                'amount'    => $data['amount'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Deposit initialized. Complete payment to fund your wallet.',
                'data'    => $lencoData,
            ], 201);

            // return $this->ok('Deposit initialized. Complete payment to fund your wallet.', [
            //     'reference'    => $reference,
            //     'amount'       => (float) $data['amount'],
            //     'checkout_url' => $lencoData['checkoutUrl'] ?? $lencoData['link'] ?? null,
            //     'instructions' => $lencoData,
            //     'status'       => 'pending',
            // ], 201);

        } catch (\RuntimeException $e) {
            // Lenco call failed — mark our record failed immediately so it
            // can never be used again, then surface the error.
            $transaction->update(['status' => 'failed', 'meta' => array_merge(
                $transaction->meta ?? [],
                ['error' => $e->getMessage(), 'failed_at' => now()->toIso8601String()]
            )]);

            Log::error('[Wallet] Deposit initialization failed', [
                'user_id'   => $user->id,
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return $this->error('Could not initialize deposit. Please try again.', 502);
            // return $this->error('Deposit initialization failed: ' . $e->getMessage(), 502);
        }
    }

public function verifyDeposit(Request $request, LencoService $lenco)
{
    $request->validate([
        'reference' => 'required|string',
    ]);

    try {
        $data = $lenco->verifyDeposit($request->reference);

        return response()->json([
            'success' => true,
            'message' => 'Deposit status retrieved successfully',
            'data'    => $data,
        ]);

    } catch (\Throwable $e) {
        Log::error('[Wallet] Verify deposit failed', [
            'reference' => $request->reference,
            'error'     => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to verify deposit',
        ], 500);
    }
}

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/wallet/withdraw
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Step 1 of a withdrawal:
     *   1. Validate amount and bank details.
     *   2. Lock the wallet row and verify sufficient balance.
     *   3. Debit the ledger IMMEDIATELY (optimistic debit) so the balance
     *      can't be double-spent while we wait for Lenco.
     *   4. Persist a PENDING transaction.
     *   5. Call Lenco transfers API.
     *   6. If Lenco fails synchronously, refund the debit right here.
     *      If Lenco accepts but transfer later fails, webhook handles refund.
     *
     * NOTE: The debit is applied before the Lenco call because Lenco is
     * asynchronous — we cannot hold a lock open across an HTTP request.
     * The webhook will either confirm (no action) or fail (refund).
     */
    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'         => ['required', 'numeric', 'min:100'],
            'account_number' => ['required', 'string', 'digits:10'],
            'bank_code'      => ['required', 'string'],
            'account_name'   => ['required', 'string', 'max:100'],
            'narration'      => ['nullable', 'string', 'max:100'],
        ]);

        $user      = $request->user();
        $reference = Transaction::generateReference('WDR');

        // Wrap balance check + debit + transaction creation in one DB transaction
        // with a row-level lock to prevent race conditions (concurrent withdrawals).
        try {
            $transaction = DB::transaction(function () use ($user, $data, $reference) {

                /** @var Wallet $wallet */
                $wallet = Wallet::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->lockForUpdate()   // <-- row-level lock prevents double-spend
                    ->firstOrFail();

                // Throws DomainException if balance insufficient
                $wallet->debit((float) $data['amount']);

                return Transaction::create([
                    'user_id'   => $user->id,
                    'wallet_id' => $wallet->id,
                    'type'      => 'withdrawal',
                    'amount'    => $data['amount'],
                    'reference' => $reference,
                    'status'    => 'pending',
                    'provider'  => 'lenco',
                    'meta'      => [
                        'account_number' => $data['account_number'],
                        'bank_code'      => $data['bank_code'],
                        'account_name'   => $data['account_name'],
                        'initiated_at'   => now()->toIso8601String(),
                    ],
                ]);
            });

        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);

        } catch (\Throwable $e) {
            Log::error('[Wallet] Withdraw DB error', ['error' => $e->getMessage()]);
            return $this->error('Withdrawal could not be processed.', 500);
        }

        // ── Call Lenco (outside the DB transaction — never hold locks over HTTP) ──
        try {
            $lencoData = $this->lenco->withdraw(
                amount:        (float) $data['amount'],
                reference:     $reference,
                accountNumber: $data['account_number'],
                bankCode:      $data['bank_code'],
                accountName:   $data['account_name'],
                narration:     $data['narration'] ?? 'Wallet withdrawal',
            );

            $transaction->update(['meta' => array_merge(
                $transaction->meta ?? [],
                ['lenco_transfer' => $lencoData]
            )]);

            Log::info('[Wallet] Withdrawal submitted to Lenco', [
                'user_id'   => $user->id,
                'reference' => $reference,
                'amount'    => $data['amount'],
            ]);

            return $this->ok('Withdrawal submitted. Funds will be sent shortly.', [
                'reference' => $reference,
                'amount'    => (float) $data['amount'],
                'status'    => 'pending',
            ]);

        } catch (\RuntimeException $e) {
            // Lenco rejected the request synchronously — reverse our debit now.
            // (transfer.failed webhook may also fire, but the refund logic in
            // WebhookController is idempotent and will skip an already-refunded tx.)
            $this->refundFailedWithdrawal($transaction, $e->getMessage());

            Log::error('[Wallet] Lenco withdrawal call failed, balance refunded', [
                'user_id'   => $user->id,
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return $this->error('Withdrawal failed: ' . $e->getMessage(), 502);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function getActiveWallet(Request $request): Wallet
    {
        $wallet = Wallet::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        if (!$wallet) {
            abort(404, 'Active wallet not found.');
        }

        return $wallet;
    }

    /** Find or auto-provision a wallet for the user. */
    private function resolveWallet($user): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0.00, 'status' => 'active']
        );
    }

    /**
     * Refund a failed withdrawal synchronously.
     * Marks transaction as failed and credits the balance back.
     */
    private function refundFailedWithdrawal(Transaction $transaction, string $reason): void
    {
        DB::transaction(function () use ($transaction, $reason) {
            $transaction->update([
                'status' => 'failed',
                'meta'   => array_merge($transaction->meta ?? [], [
                    'error'     => $reason,
                    'failed_at' => now()->toIso8601String(),
                ]),
            ]);

            $transaction->wallet()->lockForUpdate()->first()->credit($transaction->amount);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JSON response helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function ok(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    private function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}