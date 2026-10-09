<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Players are Telegram accounts. They never have passwords; they authenticate
     * with signed Telegram Mini App init data and receive a server-side app session.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('username', 64)->nullable()->index();
            $table->string('first_name', 128);
            $table->string('last_name', 128)->nullable();
            $table->string('photo_url', 512)->nullable();
            $table->string('language_code', 12)->nullable();
            $table->boolean('is_premium')->default(false);
            $table->string('referral_code', 16)->unique();
            $table->foreignId('referrer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('referred_at')->nullable();
            $table->timestamp('referral_qualified_at')->nullable()->index();
            $table->string('status', 16)->default('active')->index();
            $table->string('status_reason', 255)->nullable();
            $table->boolean('is_flagged')->default(false)->index();
            $table->text('admin_note')->nullable();
            $table->string('registration_source', 16)->default('bot');
            $table->string('registration_ip_hash', 64)->nullable()->index();
            $table->string('last_ip_hash', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->index(['referrer_id', 'created_at']);
        });

        Schema::create('app_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });

        // Session storage for the administrator dashboard (cookie-based, CSRF protected).
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('app_sessions');
        Schema::dropIfExists('users');
    }
};
