<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transactional balance model. Every change is mirrored by an immutable
        // ledger entry, and `wallet:reconcile` proves the two always agree.
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('available', 20, 6)->default(0);
            $table->decimal('reserved', 20, 6)->default(0);
            $table->decimal('total_earned', 20, 6)->default(0);
            $table->decimal('total_withdrawn', 20, 6)->default(0);
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->decimal('available_delta', 20, 6);
            $table->decimal('reserved_delta', 20, 6)->default(0);
            $table->decimal('available_after', 20, 6);
            $table->decimal('reserved_after', 20, 6);
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('status', 16)->default('posted');
            $table->string('idempotency_key', 191)->unique();
            $table->string('description', 255)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            // Unique: a ledger entry can be reversed at most once.
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('ledger_entries')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        // Administrator-funded reward budget. Rewards can never exceed it.
        Schema::create('reward_budgets', function (Blueprint $table) {
            $table->id();
            $table->decimal('balance', 20, 6)->default(0);
            $table->decimal('total_funded', 20, 6)->default(0);
            $table->decimal('total_issued', 20, 6)->default(0);
            $table->decimal('total_returned', 20, 6)->default(0);
            $table->timestamps();
        });

        DB::table('reward_budgets')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('budget_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16); // fund | defund
            $table->decimal('amount', 20, 6);
            $table->decimal('balance_after', 20, 6);
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_transactions');
        Schema::dropIfExists('reward_budgets');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('wallets');
    }
};
