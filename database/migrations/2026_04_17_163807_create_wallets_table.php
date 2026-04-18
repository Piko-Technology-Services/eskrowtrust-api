<?php

// database/migrations/2024_01_01_000001_create_wallets_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->unique()            // one wallet per user
                  ->constrained()
                  ->cascadeOnDelete();

            $table->decimal('balance', 15, 2)->default(0.00)->unsigned();
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};