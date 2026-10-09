<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->string('full_name', 120);
            $table->string('public_alias', 64)->nullable();
            $table->string('network', 16);
            $table->string('address', 128)->index();
            $table->decimal('amount', 20, 6);
            $table->decimal('fee', 20, 6)->default(0);
            $table->decimal('net_amount', 20, 6);
            $table->text('note')->nullable();
            $table->string('status', 16)->index();
            $table->string('reject_reason', 255)->nullable();
            $table->string('tx_hash', 128)->nullable()->unique();
            $table->string('payment_reference', 128)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('processing_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('processing_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
