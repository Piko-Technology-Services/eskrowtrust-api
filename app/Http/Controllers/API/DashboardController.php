<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get or create wallet
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'status' => 'active']
        );

        // Get recent transactions (latest 5–10)
        $transactions = $wallet->transactions()
            ->latest()
            ->take(6)
            ->get()
            ->map(function ($txn) {
                return [
                    'id'        => $txn->id,
                    'type'      => $txn->type,
                    'amount'    => (float) $txn->amount,
                    'status'    => $txn->status,
                    'reference' => $txn->reference,
                    'date'      => $txn->created_at->toDateTimeString(),
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully.',
            'data' => [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                ],
                'wallet' => [
                    'id'      => $wallet->id,
                    'balance' => (float) $wallet->balance,
                    'status'  => $wallet->status,
                ],
                'transactions' => $transactions,
            ]
        ]);
    }
}