<?php

// app/Services/LencoService.php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LencoService
 *
 * Single gateway to the Lenco v2 API.
 * ─────────────────────────────────────
 * Base URL : https://api.lenco.co/access/v2
 * Auth     : Authorization: Bearer {LENCO_SECRET_PAYMENTS_KEY}
 *
 * THIS CLASS ONLY MOVES MONEY.
 * It NEVER touches wallet balances or transaction statuses.
 * Those responsibilities belong exclusively to WebhookController.
 */
class LencoService
{
    private const BASE_URL = 'https://api.lenco.co/access/v2';

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.lenco.api_key')
            ?? throw new \RuntimeException('LENCO_SECRET_PAYMENTS_KEY is not configured.');


    }

    // ─────────────────────────────────────────────────────────────────────────
    // Deposits  — Lenco Collections API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Initialize a deposit (collection) for a user.
     *
     * Lenco will return a checkout URL or virtual account details.
     * The caller must persist the returned data in transaction.meta.
     *
     * @param  float  $amount       Major currency units (e.g. 5000.00 = NGN 5,000)
     * @param  string $reference    Your unique transaction reference
     * @param  string $email        Payer's email (used by Lenco for receipt)
     * @param  string $callbackUrl  URL Lenco POSTs webhook events to
     * @return array                Raw Lenco API response data
     */
public function initializeDeposit(
    float $amount,
    string $reference,
    string $phone,
    string $operator,
    string $country = 'zm',
    string $bearer = 'merchant'
): array {

    $payload = [
        'amount'    => $amount,
        'reference' => $reference,
        'phone'     => $phone,
        'operator'  => $operator,
        'country'   => $country,
        'bearer'    => $bearer,
    ];

    Log::info('[Lenco] Mobile Money Deposit Init', $payload);

    $response = $this->post('/collections/mobile-money', $payload);

    $resp = $this->unwrap($response, 'initializeDeposit');

    Log::info('Lenco Response: ', $resp);

    return $this->unwrap($response, 'initializeDeposit');
}

public function verifyDeposit(string $reference): array
{
    $response = $this->get("/collections/{$reference}");

    return $this->unwrap($response, 'verifyDeposit');
}

    // ─────────────────────────────────────────────────────────────────────────
    // Withdrawals  — Lenco Transfers API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Initiate a bank transfer (withdrawal) from your Lenco master account
     * to an external bank account.
     *
     * Balance on the user's internal ledger is ALREADY debited before this
     * call is made (inside WalletController). If this call fails the
     * WebhookController refunds via transfer.failed.
     *
     * @param  float  $amount         Major currency units
     * @param  string $reference      Your unique transaction reference
     * @param  string $accountNumber  Recipient bank account number
     * @param  string $bankCode       Recipient bank code (CBN sort code)
     * @param  string $accountName    Recipient account name (for validation)
     * @param  string $narration      Transfer description (max 100 chars)
     * @return array                  Raw Lenco API response data
     */
    public function withdraw(
        float  $amount,
        string $reference,
        string $accountNumber,
        string $bankCode,
        string $accountName,
        string $narration = 'Wallet withdrawal'
    ): array {
        $payload = [
            'amount'        => $this->toMinorUnits($amount),
            'reference'     => $reference,
            'accountNumber' => $accountNumber,
            'bankCode'      => $bankCode,
            'accountName'   => $accountName,
            'narration'     => substr($narration, 0, 100),
            'currency'      => config('services.lenco.currency', 'NGN'),
        ];

        Log::info('[Lenco] Initiating withdrawal', [
            'reference' => $reference,
            'amount'    => $amount,
            'bank'      => $bankCode,
        ]);

        $response = $this->post('/transfers', $payload);

        return $this->unwrap($response, 'withdraw');
    }

    /**
     * Resolve a bank account number to a name before withdrawing.
     * Always call this before withdraw() to catch typos early.
     */
    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        $response = $this->get('/resolve', [
            'accountNumber' => $accountNumber,
            'bankCode'      => $bankCode,
        ]);

        return $this->unwrap($response, 'resolveAccount');
    }

    /**
     * Fetch a list of supported banks.
     */
    public function getBanks(): array
    {
        $response = $this->get('/banks');
        return $this->unwrap($response, 'getBanks');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Webhook Signature Verification
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verify the X-Lenco-Signature header sent with every webhook event.
     *
     * Lenco signs the raw request body using HMAC-SHA512 with a key derived
     * from SHA256( API_KEY ). This stops spoofed webhook calls from
     * corrupting your ledger.
     *
     * @param  string $rawBody     The raw POST body (from $request->getContent())
     * @param  string $signature   The value of the X-Lenco-Signature header
     * @return bool
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        // Derive the signing key: SHA256 of the API key (as Lenco specifies)
        $signingKey = hash('sha256', $this->apiKey);

        // Compute the expected HMAC-SHA512 of the raw body
        $expected = hash_hmac('sha512', $rawBody, $signingKey);

        // Constant-time comparison prevents timing attacks
        return hash_equals($expected, strtolower($signature));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTTP Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function http()
    {

    
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])
        ->timeout(30)
        ->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException);
    }

    private function get(string $path, array $query = []): Response
    {
        return $this->http()->get(self::BASE_URL . $path, $query);
    }

    private function post(string $path, array $payload): Response
    {
        return $this->http()->post(self::BASE_URL . $path, $payload);
    }

    /**
     * Unwrap a Lenco response or throw a descriptive exception.
     * Lenco always returns { "status": true|false, "message": "...", "data": {...} }
     */
    private function unwrap(Response $response, string $context): array
    {
        if ($response->serverError()) {
            Log::error("[Lenco:{$context}] Server error", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("Lenco server error ({$response->status()}) on {$context}");
        }

        $body = $response->json();

        if (!($body['status'] ?? false)) {
            $message = $body['message'] ?? 'Unknown Lenco error';
            Log::warning("[Lenco:{$context}] API error", ['message' => $message, 'body' => $body]);
            throw new \RuntimeException("Lenco API error: {$message}");
        }

        return $body['data'] ?? $body;
    }

    /**
     * Convert major currency units to minor units (kobo/cents).
     * e.g. 5000.00 NGN → 500000 kobo
     */
    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}