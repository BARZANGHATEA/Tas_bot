<?php

namespace App\Services;

use App\Contracts\DiceRoller;
use App\Enums\LedgerType;
use App\Enums\MatchStatus;
use App\Exceptions\BudgetExhaustedException;
use App\Exceptions\BusinessRuleException;
use App\Models\GameMatch;
use App\Models\LedgerEntry;
use App\Models\MatchRoll;
use App\Models\User;
use App\Services\Telegram\Links;
use App\Services\Telegram\NotificationService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two-player matches with a deterministic server-side state machine:
 *
 *   waiting ──join──▶ ready ──first roll──▶ playing ──last roll──▶ completed
 *      │                │                     │
 *      └── cancel/expire ┴──────── expire ─────┘──▶ cancelled
 *
 * Each player rolls their own dice once per round; the server compares
 * totals, keeps score, applies the tie rule and settles exactly once.
 * Matches are free to play: there are no stakes, only optional
 * platform-sponsored rewards.
 */
class MatchService
{
    public function __construct(
        private readonly DiceRoller $dice,
        private readonly Settings $settings,
        private readonly WalletService $wallet,
        private readonly ReferralService $referrals,
        private readonly NotificationService $notifications,
        private readonly Links $links,
    ) {}

    public function rules(): array
    {
        return [
            'enabled' => $this->settings->bool('game.multi.enabled') && ! $this->settings->bool('game.maintenance'),
            'dice_count' => $this->settings->int('game.multi.dice_count', 2),
            'rounds' => $this->settings->int('game.multi.rounds', 3),
            'tie_rule' => $this->settings->string('game.multi.tie_rule', 'extra_round'),
            'max_extra_rounds' => $this->settings->int('game.multi.max_extra_rounds', 3),
            'reward_mode' => $this->settings->string('game.multi.reward_mode', 'fixed'),
            'reward' => Money::str($this->settings->money('game.multi.reward')),
            'pool' => Money::str($this->settings->money('game.multi.pool')),
            'expire_minutes' => $this->settings->int('game.multi.expire_minutes', 30),
            'rules_text' => $this->settings->string('game.multi.rules_text'),
        ];
    }

    public function create(User $user, string $visibility, ?string $invitee = null): GameMatch
    {
        $this->assertCanPlay($user);
        $visibility = $visibility === 'private' ? 'private' : 'public';

        $invited = null;
        if ($invitee !== null && trim($invitee) !== '') {
            $invited = $this->resolveInvitee(trim($invitee));
            if (! $invited || $invited->id === $user->id || ! $invited->isActive()) {
                throw new BusinessRuleException('That player was not found. They must have opened the bot at least once.', 'invitee_not_found', 404);
            }
            $visibility = 'private';
        }

        $match = DB::transaction(function () use ($user, $visibility, $invited) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $open = GameMatch::query()
                ->where('creator_id', $user->id)
                ->whereIn('status', MatchStatus::openValues())
                ->count();
            if ($open >= $this->settings->int('game.multi.max_open', 2)) {
                throw new BusinessRuleException('You already have the maximum number of open matches.', 'too_many_open');
            }

            $cooldown = $this->settings->int('game.multi.cooldown_seconds');
            $last = GameMatch::query()->where('creator_id', $user->id)->latest('id')->value('created_at');
            if ($cooldown > 0 && $last && now()->lt(\Carbon\CarbonImmutable::parse($last)->addSeconds($cooldown))) {
                $wait = (int) ceil(now()->diffInSeconds(\Carbon\CarbonImmutable::parse($last)->addSeconds($cooldown), false));
                throw new BusinessRuleException("Please wait {$wait}s before creating another match.", 'cooldown', 429, ['retry_after' => $wait]);
            }

            $this->assertDailyLimit($user);

            $rules = $this->rules();
            unset($rules['enabled'], $rules['rules_text']);

            return GameMatch::query()->create([
                'uuid' => (string) Str::uuid(),
                'invite_code' => Str::random(20),
                'creator_id' => $user->id,
                'invited_user_id' => $invited?->id,
                'visibility' => $visibility,
                'status' => MatchStatus::Waiting,
                'dice_count' => max(1, min(5, $rules['dice_count'])),
                'base_rounds' => max(1, $rules['rounds']),
                'total_rounds' => max(1, $rules['rounds']),
                'current_round' => 1,
                'rules' => $rules,
                'expires_at' => now()->addMinutes(max(1, $rules['expire_minutes'])),
            ]);
        });

        if ($invited) {
            $this->notifications->toUser($invited, 'tpl.match_invite', ['inviter' => $user->displayName()],
                $this->links->openAppKeyboard(['match' => $match->uuid, 'code' => $match->invite_code]));
        }

        return $match;
    }

    public function join(User $user, GameMatch $match, ?string $code): GameMatch
    {
        $this->assertCanPlay($user);

        $match = DB::transaction(function () use ($user, $match, $code) {
            $match = GameMatch::query()->whereKey($match->id)->lockForUpdate()->firstOrFail();

            if ($match->creator_id === $user->id) {
                throw new BusinessRuleException('You cannot join your own match.', 'own_match');
            }
            if ($match->opponent_id === $user->id) {
                return $match; // Repeated join request: idempotent.
            }
            if ($match->status !== MatchStatus::Waiting) {
                throw new BusinessRuleException('This match is no longer open.', 'match_closed', 409);
            }
            if ($match->expires_at && $match->expires_at->isPast()) {
                $this->transition($match, MatchStatus::Cancelled, ['cancelled_at' => now(), 'cancel_reason' => 'expired']);
                throw new BusinessRuleException('This match has expired.', 'match_expired', 409);
            }
            if ($match->visibility === 'private') {
                if ($code === null || ! hash_equals($match->invite_code, $code)) {
                    throw new BusinessRuleException('This is a private match. You need a valid invitation.', 'invite_required', 403);
                }
                if ($match->invited_user_id !== null && $match->invited_user_id !== $user->id) {
                    throw new BusinessRuleException('This invitation is for another player.', 'invite_required', 403);
                }
            }

            $this->assertDailyLimit($user);

            $this->transition($match, MatchStatus::Ready, [
                'opponent_id' => $user->id,
                'joined_at' => now(),
                'expires_at' => now()->addMinutes(max(1, (int) ($match->rules['expire_minutes'] ?? 30))),
            ]);

            return $match;
        });

        $this->notifications->toUser($match->creator, 'tpl.match_joined', ['opponent' => $user->displayName()],
            $this->links->openAppKeyboard(['match' => $match->uuid]), 'match-joined:'.$match->id);

        return $match;
    }

    /** @return array{match: GameMatch, roll: MatchRoll} */
    public function roll(User $user, GameMatch $match): array
    {
        $this->assertCanPlay($user);

        try {
            [$match, $roll, $completed] = DB::transaction(function () use ($user, $match) {
                $match = GameMatch::query()->whereKey($match->id)->lockForUpdate()->firstOrFail();

                if (! $match->isParticipant($user)) {
                    throw new BusinessRuleException('You are not a player in this match.', 'not_participant', 403);
                }
                if (! in_array($match->status, [MatchStatus::Ready, MatchStatus::Playing], true)) {
                    throw new BusinessRuleException('This match is not in progress.', 'match_not_active', 409);
                }
                if ($match->expires_at && $match->expires_at->isPast()) {
                    $this->transition($match, MatchStatus::Cancelled, ['cancelled_at' => now(), 'cancel_reason' => 'expired']);

                    return [$match, null, false];
                }

                $round = $match->current_round;
                $alreadyRolled = MatchRoll::query()
                    ->where('match_id', $match->id)->where('user_id', $user->id)->where('round_no', $round)
                    ->exists();
                if ($alreadyRolled) {
                    throw new BusinessRuleException('Waiting for your opponent to roll.', 'already_rolled', 409);
                }

                $dice = [];
                for ($i = 0; $i < $match->dice_count; $i++) {
                    $dice[] = $this->dice->roll(6);
                }

                $roll = MatchRoll::query()->create([
                    'match_id' => $match->id,
                    'user_id' => $user->id,
                    'round_no' => $round,
                    'dice' => $dice,
                    'total' => array_sum($dice),
                ]);

                $this->transition($match, MatchStatus::Playing, [
                    'started_at' => $match->started_at ?? now(),
                    'expires_at' => now()->addMinutes(max(1, (int) ($match->rules['expire_minutes'] ?? 30))),
                ]);

                $completed = $this->resolveRoundIfComplete($match, $round);

                return [$match, $roll, $completed];
            });
        } catch (QueryException $e) {
            // Unique (match, user, round) hit by a double-submitted roll.
            throw new BusinessRuleException('Waiting for your opponent to roll.', 'already_rolled', 409);
        }

        if ($roll === null) {
            throw new BusinessRuleException('This match has expired.', 'match_expired', 409);
        }

        if ($completed) {
            $this->afterCompletion($match);
        }

        return ['match' => $match->fresh(), 'roll' => $roll];
    }

    public function cancel(User $user, GameMatch $match): GameMatch
    {
        return DB::transaction(function () use ($user, $match) {
            $match = GameMatch::query()->whereKey($match->id)->lockForUpdate()->firstOrFail();

            if ($match->creator_id !== $user->id) {
                throw new BusinessRuleException('Only the creator can cancel this match.', 'not_creator', 403);
            }
            if ($match->status !== MatchStatus::Waiting) {
                throw new BusinessRuleException('Matches can only be cancelled before an opponent joins.', 'match_not_cancellable', 409);
            }

            $this->transition($match, MatchStatus::Cancelled, ['cancelled_at' => now(), 'cancel_reason' => 'creator']);

            return $match;
        });
    }

    /** Cancel matches whose inactivity deadline passed. Returns the number expired. */
    public function expireStale(): int
    {
        $count = 0;
        GameMatch::query()
            ->whereIn('status', MatchStatus::openValues())
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->limit(500)
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $match = GameMatch::query()->whereKey($id)->lockForUpdate()->first();
                    if ($match && $match->status->isOpen() && $match->expires_at?->isPast()) {
                        $this->transition($match, MatchStatus::Cancelled, ['cancelled_at' => now(), 'cancel_reason' => 'expired']);
                        $count++;
                    }
                });
            });

        return $count;
    }

    public function openPublicMatches(User $viewer, int $limit = 20)
    {
        return GameMatch::query()
            ->with('creator')
            ->where('status', MatchStatus::Waiting)
            ->where('visibility', 'public')
            ->where('creator_id', '!=', $viewer->id)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function matchesFor(User $user, int $limit = 20)
    {
        return GameMatch::query()
            ->with(['creator', 'opponent'])
            ->where(fn ($q) => $q->where('creator_id', $user->id)->orWhere('opponent_id', $user->id))
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /** Whether $viewer may see this match (participants, public lobby, or valid invitation). */
    public function canView(User $viewer, GameMatch $match, ?string $code = null): bool
    {
        if ($match->isParticipant($viewer)) {
            return true;
        }
        if ($match->status !== MatchStatus::Waiting) {
            return false;
        }

        return $match->visibility === 'public' || ($code !== null && hash_equals($match->invite_code, $code));
    }

    public function present(GameMatch $match, User $viewer): array
    {
        $match->loadMissing(['creator', 'opponent', 'rolls']);
        $isCreator = $match->creator_id === $viewer->id;
        $isParticipant = $match->isParticipant($viewer);
        $me = $isCreator ? $match->creator : ($isParticipant ? $match->opponent : null);
        $them = $isCreator ? $match->opponent : $match->creator;

        $rounds = [];
        for ($r = 1; $r <= $match->total_rounds; $r++) {
            $mine = $me ? $match->rolls->first(fn ($x) => $x->round_no === $r && $x->user_id === $me->id) : null;
            $theirs = $them ? $match->rolls->first(fn ($x) => $x->round_no === $r && $x->user_id === $them->id) : null;
            if ($r > $match->current_round && ! $mine && ! $theirs) {
                continue;
            }
            $rounds[] = [
                'round' => $r,
                'you' => $mine ? ['dice' => $mine->dice, 'total' => $mine->total] : null,
                'them' => $theirs ? ['dice' => $theirs->dice, 'total' => $theirs->total] : null,
            ];
        }

        $myRolledCurrent = $me && $match->rolls->contains(fn ($x) => $x->round_no === $match->current_round && $x->user_id === $me->id);
        $active = in_array($match->status, [MatchStatus::Ready, MatchStatus::Playing], true);

        $result = null;
        if ($match->status === MatchStatus::Completed) {
            $result = $match->is_tie ? 'tie' : ($me && $match->winner_id === $me->id ? 'won' : ($isParticipant ? 'lost' : 'finished'));
        }

        $person = fn (?User $u) => $u ? ['id' => $u->publicId(), 'name' => $u->displayName(), 'username' => $u->username, 'initials' => $u->initials()] : null;

        return [
            'uuid' => $match->uuid,
            'status' => $match->status->value,
            'visibility' => $match->visibility,
            'is_creator' => $isCreator,
            'is_participant' => $isParticipant,
            'you' => $person($me),
            'opponent' => $person($them),
            'dice_count' => $match->dice_count,
            'base_rounds' => $match->base_rounds,
            'total_rounds' => $match->total_rounds,
            'current_round' => $match->current_round,
            'score' => [
                'you' => $isCreator ? $match->creator_score : $match->opponent_score,
                'them' => $isCreator ? $match->opponent_score : $match->creator_score,
            ],
            'rounds' => $rounds,
            'can_roll' => $isParticipant && $active && ! $myRolledCurrent,
            'waiting_for_opponent' => $isParticipant && (($active && $myRolledCurrent) || $match->status === MatchStatus::Waiting),
            'can_join' => ! $isParticipant && $match->status === MatchStatus::Waiting,
            'can_cancel' => $isCreator && $match->status === MatchStatus::Waiting,
            'result' => $result,
            'is_tie' => $match->is_tie,
            'reward_status' => $match->reward_status,
            'rules' => [
                'reward_mode' => $match->rules['reward_mode'] ?? 'none',
                'reward' => Money::format($match->rules['reward'] ?? '0'),
                'pool' => Money::format($match->rules['pool'] ?? '0'),
                'tie_rule' => $match->rules['tie_rule'] ?? 'draw',
            ],
            'cancel_reason' => $match->cancel_reason,
            'expires_at' => $match->expires_at?->toIso8601String(),
            'invite_link' => $isCreator && $match->status === MatchStatus::Waiting ? $this->links->matchLink($match->invite_code) : null,
            'invite_code' => $isCreator && $match->status === MatchStatus::Waiting ? $match->invite_code : null,
            'created_at' => $match->created_at?->toIso8601String(),
        ];
    }

    private function resolveRoundIfComplete(GameMatch $match, int $round): bool
    {
        $rolls = MatchRoll::query()->where('match_id', $match->id)->where('round_no', $round)->get()->keyBy('user_id');
        if ($rolls->count() < 2) {
            return false;
        }

        $creatorTotal = $rolls[$match->creator_id]->total;
        $opponentTotal = $rolls[$match->opponent_id]->total;

        if ($creatorTotal > $opponentTotal) {
            $match->creator_score++;
        } elseif ($opponentTotal > $creatorTotal) {
            $match->opponent_score++;
        }

        if ($round < $match->total_rounds) {
            $match->current_round = $round + 1;
            $match->save();

            return false;
        }

        if ($match->creator_score !== $match->opponent_score) {
            $winner = $match->creator_score > $match->opponent_score ? $match->creator_id : $match->opponent_id;
            $this->complete($match, $winner, false);

            return true;
        }

        $rules = $match->rules;
        $extraUsed = $match->total_rounds - $match->base_rounds;
        if (($rules['tie_rule'] ?? 'draw') === 'extra_round' && $extraUsed < (int) ($rules['max_extra_rounds'] ?? 0)) {
            $match->total_rounds++;
            $match->current_round = $round + 1;
            $match->save();

            return false;
        }

        $this->complete($match, null, true);

        return true;
    }

    private function complete(GameMatch $match, ?int $winnerId, bool $isTie): void
    {
        $this->transition($match, MatchStatus::Completed, [
            'winner_id' => $winnerId,
            'is_tie' => $isTie,
            'completed_at' => now(),
        ]);

        $this->settle($match);
    }

    /** Pays sponsored rewards. Runs once: guarded by settled_at and ledger idempotency keys. */
    private function settle(GameMatch $match): void
    {
        if ($match->settled_at !== null) {
            return;
        }

        $rules = $match->rules;
        $mode = $rules['reward_mode'] ?? 'none';
        $tieRule = $rules['tie_rule'] ?? 'draw';
        $payouts = [];

        if ($mode === 'fixed') {
            $reward = Money::of($rules['reward'] ?? '0');
            if ($match->winner_id) {
                $payouts[$match->winner_id] = $reward;
            } elseif ($match->is_tie && $tieRule === 'split') {
                $half = $reward->dividedBy(2, Money::SCALE, RoundingMode::DOWN);
                $payouts[$match->creator_id] = $half;
                $payouts[$match->opponent_id] = $half;
            }
        } elseif ($mode === 'pooled' && ! ($match->is_tie && $tieRule === 'draw')) {
            $pool = Money::of($rules['pool'] ?? '0');
            $totalScore = $match->creator_score + $match->opponent_score;
            if ($totalScore === 0) {
                $half = $pool->dividedBy(2, Money::SCALE, RoundingMode::DOWN);
                $payouts = [$match->creator_id => $half, $match->opponent_id => $half];
            } else {
                $payouts[$match->creator_id] = $pool->multipliedBy($match->creator_score)->dividedBy($totalScore, Money::SCALE, RoundingMode::DOWN);
                $payouts[$match->opponent_id] = $pool->multipliedBy($match->opponent_score)->dividedBy($totalScore, Money::SCALE, RoundingMode::DOWN);
            }
        }

        $status = 'none';
        foreach ($payouts as $userId => $amount) {
            if (! $amount->isPositive()) {
                continue;
            }
            $player = User::query()->find($userId);
            if (! $player || ! $player->isActive()) {
                continue;
            }
            try {
                DB::transaction(fn () => $this->wallet->credit($player, $amount, LedgerType::MatchReward,
                    "match:{$match->uuid}:{$userId}", $match, 'Two-player match reward'));
                $status = $status === 'unfunded' ? 'partial' : 'credited';
            } catch (BudgetExhaustedException) {
                $status = $status === 'credited' ? 'partial' : 'unfunded';
            }
        }

        $match->forceFill(['settled_at' => now(), 'reward_status' => $status])->save();
    }

    private function afterCompletion(GameMatch $match): void
    {
        $match->loadMissing(['creator', 'opponent']);

        foreach ([$match->creator, $match->opponent] as $player) {
            if (! $player) {
                continue;
            }

            $entry = LedgerEntry::query()->where('idempotency_key', "match:{$match->uuid}:{$player->id}")->first();
            if ($entry) {
                $this->referrals->onRewardEarned($player, Money::of($entry->available_delta), $entry);
            }
            $this->referrals->checkQualification($player);

            $isCreator = $player->id === $match->creator_id;
            $mine = $isCreator ? $match->creator_score : $match->opponent_score;
            $theirs = $isCreator ? $match->opponent_score : $match->creator_score;
            $result = $match->is_tie ? 'Draw' : ($match->winner_id === $player->id ? 'You won 🏆' : 'You lost');
            if ($entry) {
                $result .= ' · +'.Money::format($entry->available_delta).' USDT';
            }

            $this->notifications->toUser($player, 'tpl.match_finished', ['result' => $result, 'score' => "{$mine}–{$theirs}"],
                $this->links->openAppKeyboard(['match' => $match->uuid]), "match-finished:{$match->id}:{$player->id}");
        }
    }

    private function transition(GameMatch $match, MatchStatus $to, array $attributes = []): void
    {
        if ($match->status !== $to && ! $match->status->canTransitionTo($to)) {
            throw new BusinessRuleException("Invalid match transition {$match->status->value} → {$to->value}.", 'invalid_transition', 409);
        }

        $match->forceFill(['status' => $to] + $attributes)->save();
    }

    private function assertCanPlay(User $user): void
    {
        if (! $user->isActive()) {
            throw new BusinessRuleException('Your account is restricted and cannot play right now.', 'account_restricted', 403);
        }
        if ($this->settings->bool('game.maintenance')) {
            throw new BusinessRuleException('Games are under maintenance. Please check back soon.', 'game_maintenance', 503);
        }
        if (! $this->settings->bool('game.multi.enabled')) {
            throw new BusinessRuleException('Two-player matches are currently disabled.', 'game_disabled', 503);
        }
    }

    private function assertDailyLimit(User $user): void
    {
        $limit = $this->settings->int('game.multi.daily_limit');
        if ($limit <= 0) {
            return;
        }

        $start = $this->settings->startOfToday();
        $count = GameMatch::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('creator_id', $user->id)->where('created_at', '>=', $start))
                ->orWhere(fn ($q) => $q->where('opponent_id', $user->id)->where('joined_at', '>=', $start)))
            ->where(fn ($q) => $q->where('status', '!=', MatchStatus::Cancelled->value)->orWhereNotNull('joined_at'))
            ->count();

        if ($count >= $limit) {
            throw new BusinessRuleException("You reached today's limit of {$limit} matches.", 'daily_limit', 429);
        }
    }

    private function resolveInvitee(string $identifier): ?User
    {
        if (preg_match('/^U0*(\d+)$/i', $identifier, $m)) {
            return User::query()->find((int) $m[1]);
        }

        return User::query()->where('username', ltrim($identifier, '@'))->first();
    }
}
