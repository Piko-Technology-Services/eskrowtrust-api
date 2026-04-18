<?php

use App\Http\Controllers\API\WalletController;
use App\Http\Controllers\API\WebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DashboardController;

Route::get('/', function () {
    return response()->json(['Eskrowtrust' => 'Global Trust in Every Transaction']);
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/complete-profile', [AuthController::class, 'completeProfile']);
    });
});

Route::middleware('auth:sanctum')->get('/dashboard', [DashboardController::class, 'index']);



// ── PUBLIC WEBHOOK (no auth) ──
Route::post('/webhook/lenco', [WebhookController::class, 'handle']);

// ── PROTECTED WALLET ROUTES ──
Route::middleware('auth:sanctum')->prefix('wallet')->group(function () {

    Route::get('/', [WalletController::class, 'show']);

    Route::get('/transactions', [WalletController::class, 'transactions']);

    Route::post('/deposit', [WalletController::class, 'deposit']);
    Route::get('/deposit/verify', [WalletController::class, 'verifyDeposit']);

    Route::post('/withdraw', [WalletController::class, 'withdraw']);

});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
