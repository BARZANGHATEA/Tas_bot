<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('icon', 16)->nullable();
            $table->string('image_url', 512)->nullable();
            $table->string('type', 32);
            $table->string('target', 255)->nullable();
            $table->unsignedInteger('target_count')->nullable();
            $table->string('verification', 32);
            $table->decimal('reward', 20, 6);
            $table->string('repeat', 16)->default('once'); // once | daily
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('total_limit')->nullable();
            $table->decimal('budget', 20, 6)->nullable();
            $table->decimal('budget_used', 20, 6)->default(0);
            $table->unsignedInteger('completions_count')->default(0);
            $table->unsignedInteger('min_games')->nullable();
            $table->unsignedInteger('min_account_age_hours')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mission_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('period_key', 16);
            $table->string('status', 16); // started | pending_review | rewarded | rejected
            $table->text('proof')->nullable();
            $table->decimal('reward', 20, 6)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('reject_reason', 255)->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->timestamps();

            // One completion per mission, user and period (once / per day).
            $table->unique(['mission_id', 'user_id', 'period_key']);
            $table->index(['status', 'created_at']);
            $table->index(['mission_id', 'status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_completions');
        Schema::dropIfExists('missions');
    }
};
