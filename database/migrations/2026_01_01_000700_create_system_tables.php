<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->longText('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 16); // admin | user | system | telegram
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->string('type', 32)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Outbox for Telegram messages: delivered after the HTTP response and by
        // the scheduler, retried with backoff, never blocking business logic.
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 64);
            $table->text('text');
            $table->json('reply_markup')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('send_after')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_after']);
        });

        Schema::create('fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);
            $table->string('severity', 16)->default('low');
            $table->json('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_flags');
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
    }
};
