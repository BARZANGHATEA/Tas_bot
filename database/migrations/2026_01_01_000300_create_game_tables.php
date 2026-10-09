<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rounds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->unsignedTinyInteger('die_one');
            $table->unsignedTinyInteger('die_two');
            $table->boolean('is_win');
            $table->decimal('reward', 20, 6)->default(0);
            $table->string('reward_status', 16)->default('none'); // none | credited | unfunded
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->json('rules')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('game_matches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invite_code', 32)->unique();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('opponent_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('invited_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 16)->default('public');
            $table->string('status', 16)->default('waiting');
            $table->unsignedTinyInteger('dice_count');
            $table->unsignedTinyInteger('total_rounds');
            $table->unsignedTinyInteger('base_rounds');
            $table->unsignedTinyInteger('current_round')->default(1);
            $table->unsignedInteger('creator_score')->default(0);
            $table->unsignedInteger('opponent_score')->default(0);
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_tie')->default(false);
            $table->json('rules');
            $table->string('reward_status', 16)->default('none');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'visibility', 'created_at']);
            $table->index(['creator_id', 'created_at']);
            $table->index(['opponent_id', 'created_at']);
        });

        Schema::create('match_rolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('game_matches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('round_no');
            $table->json('dice');
            $table->unsignedSmallInteger('total');
            $table->timestamp('created_at')->useCurrent();

            // A player can roll exactly once per round of a match.
            $table->unique(['match_id', 'user_id', 'round_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_rolls');
        Schema::dropIfExists('game_matches');
        Schema::dropIfExists('game_rounds');
    }
};
