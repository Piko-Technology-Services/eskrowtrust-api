<?php

// database/migrations/2024_01_01_000002_create_transactions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['deposit', 'withdrawal']);
            $table->decimal('amount', 15, 2)->unsigned();

            // Unique reference we generate before calling Lenco — used for
            // idempotency checks and webhook matching.
            $table->string('reference')->unique();

            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->string('provider')->default('lenco');

            // Stores raw Lenco request/response payloads for full audit trail.
            $table->json('meta')->nullable();

            $table->timestamps();

            // Indexes used by the webhook handler hot-path
            $table->index(['reference', 'status']);
            $table->index(['wallet_id', 'status']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};