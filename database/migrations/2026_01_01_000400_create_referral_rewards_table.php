<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('event', 32); // qualification | commission
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('base_amount', 20, 6)->nullable();
            $table->decimal('amount', 20, 6);
            $table->string('status', 16); // credited | reversed | skipped
            $table->string('skip_reason', 64)->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            // One reward per eligible event, whatever the number of retries.
            $table->string('idempotency_key', 191)->unique();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('reverse_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['beneficiary_id', 'created_at']);
            $table->index(['source_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
    }
};
