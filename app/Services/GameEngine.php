<?php

namespace App\Services;

use App\Contracts\DiceRoller;
use App\Enums\LedgerType;
use App\Exceptions\BudgetExhaustedException;
use App\Exceptions\BusinessRuleException;
use App\Models\GameRound;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single-player dice: two independent server-side dice; doubles win.
 *
 * The client sends nothing but an optional idempotency key. Dice, outcome and
 * reward are decided here, stored once, and paid exactly once.
 */
class GameEngine
{
    public function __construct(
        private readonly DiceRoller $dice,
        private readonly Settings $settings,
        private readonly WalletService $wallet,
        private readonly BudgetService $budget,
        private readonly ReferralService $referrals,
    ) {}

    public function rules(): array
    {
        return [
            'enabled' => $this->settings->bool('game.single.enabled') && ! $this->settings->bool('game.maintenance'),
            'maintenance' => $this->settings->bool('game.maintenance'),
            'reward' => Money::str($this->settings->money('game.single.reward')),
            'cooldown_seconds' => $this->settings->int('game.single.cooldown_seconds'),
            'daily_limit' => $this->settings->int('game.single.daily_limit'),
            'win_condition' => 'doubles',
            'win_probability' => '1/6',
            'rules_text' => $this->settings->string('game.single.rules_text'),
        ];
    }

    /**
     * Play one round.
     *
     * @return array{round: GameRound, replayed: bool}
     */
    public function play(User $user, ?string $idempotencyKey = null, ?string $ipHash = null): array
    {
        $this->assertCanPlay($user);

        $idempotencyKey = $idempotencyKey !== null && preg_match('/^[A-Za-z0-9-]{8,64}$/', $idempotencyKey)
            ? $idempotencyKey
            : (string) Str::uuid();

        $reward = $this->settings->money('game.single.reward');

        try {
            $result = DB::transaction(function () use ($user, $idempotencyKey, $ipHash, $reward) {
                // Serialise all plays of this user: cooldown and limits are race-free.
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $existing = GameRound::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return ['round' => $existing, 'replayed' => true];
                }

                $this->assertCooldownAndLimits($user);

                if ($reward->isPositive() && ! $this->budget->canIssue($reward)) {
                    throw new BudgetExhaustedException('Rewards are temporarily unavailable, so the game is paused. Please try again later.');
                }

                $dieOne = $this->dice->roll(6);
                $dieTwo = $this->dice->roll(6);
                $isWin = $dieOne === $dieTwo;

                $round = GameRound::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                    'die_one' => $dieOne,
                    'die_two' => $dieTwo,
                    'is_win' => $isWin,
                    'reward' => '0',
                    'reward_status' => 'none',
                    'rules' => ['reward' => Money::str($reward), 'win_condition' => 'doubles'],
                    'ip_hash' => $ipHash,
                ]);

                if ($isWin && $reward->isPositive()) {
                    try {
                        $entry = DB::transaction(fn () => $this->wallet->credit(
                            $user, $reward, LedgerType::GameReward, 'game:'.$round->uuid, $round,
                            "Doubles {$dieOne}+{$dieTwo}",
                        ));
                        $this->settleRound($round, Money::str($reward), 'credited', $entry->id);
                    } catch (BudgetExhaustedException $e) {
                        // Outcome stands; the reward could not be funded (daily cap or empty budget).
                        $this->settleRound($round, '0', 'unfunded', null, $e->errorCode);
                    }
                }

                return ['round' => $round->fresh(), 'replayed' => false];
            });
        } catch (QueryException $e) {
            // Two requests with the same idempotency key raced: return the stored round.
            $existing = GameRound::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
            if (! $existing) {
                throw $e;
            }
            $result = ['round' => $existing, 'replayed' => true];
        }

        if (! $result['replayed']) {
            $this->afterRound($user, $result['round']);
        }

        return $result;
    }

    public function assertCanPlay(User $user): void
    {
        if (! $user->isActive()) {
            throw new BusinessRuleException('Your account is restricted and cannot play right now.', 'account_restricted', 403);
        }
        if ($this->settings->bool('game.maintenance')) {
            throw new BusinessRuleException('Games are under maintenance. Please check back soon.', 'game_maintenance', 503);
        }
        if (! $this->settings->bool('game.single.enabled')) {
            throw new BusinessRuleException('This game is currently disabled.', 'game_disabled', 503);
        }

        $minAge = $this->settings->int('game.single.min_account_age_minutes');
        if ($minAge > 0 && $user->created_at->gt(now()->subMinutes($minAge))) {
            throw new BusinessRuleException("New accounts can play after {$minAge} minutes.", 'account_too_new');
        }
    }

    public function cooldownRemaining(User $user): int
    {
        $cooldown = $this->settings->int('game.single.cooldown_seconds');
        if ($cooldown <= 0) {
            return 0;
        }

        $last = GameRound::query()->where('user_id', $user->id)->latest('id')->value('created_at');
        if (! $last) {
            return 0;
        }

        $readyAt = CarbonImmutable::parse($last)->addSeconds($cooldown);

        return max(0, (int) ceil(now()->diffInSeconds($readyAt, false)));
    }

    public function roundsToday(User $user): int
    {
        return GameRound::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $this->settings->startOfToday())
            ->count();
    }

    private function assertCooldownAndLimits(User $user): void
    {
        $remaining = $this->cooldownRemaining($user);
        if ($remaining > 0) {
            throw new BusinessRuleException("Please wait {$remaining}s before the next roll.", 'cooldown', 429, ['retry_after' => $remaining]);
        }

        $limit = $this->settings->int('game.single.daily_limit');
        if ($limit > 0 && $this->roundsToday($user) >= $limit) {
            throw new BusinessRuleException("You played all {$limit} rounds for today. Come back tomorrow!", 'daily_limit', 429);
        }
    }

    /** Rounds are immutable once settled; this is the single settlement write. */
    private function settleRound(GameRound $round, string $reward, string $status, ?int $ledgerId, ?string $reason = null): void
    {
        GameRound::query()->whereKey($round->id)->where('reward_status', 'none')->toBase()->update([
            'reward' => $reward,
            'reward_status' => $status,
            'ledger_entry_id' => $ledgerId,
            'rules' => json_encode(($round->rules ?? []) + ($reason ? ['unfunded_reason' => $reason] : [])),
        ]);
    }

    private function afterRound(User $user, GameRound $round): void
    {
        if ($round->reward_status === 'credited' && $entry = LedgerEntry::query()->find($round->ledger_entry_id)) {
            $this->referrals->onRewardEarned($user, Money::of($round->reward), $entry);
        }
        $this->referrals->checkQualification($user);
    }
}
