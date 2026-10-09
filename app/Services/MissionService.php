<?php

namespace App\Services;

use App\Enums\CompletionStatus;
use App\Enums\LedgerType;
use App\Enums\MissionType;
use App\Enums\MissionVerification;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\GameRound;
use App\Models\LedgerEntry;
use App\Models\MatchRoll;
use App\Models\Mission;
use App\Models\MissionCompletion;
use App\Models\User;
use App\Services\Telegram\NotificationService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Missions are only rewarded after a real verification:
 *  - Telegram channel/group: Bot API membership check;
 *  - Instagram / custom: a moderator reviews the submitted proof;
 *  - website visit: the link must have been opened through the app and the
 *    minimum time must have elapsed (low assurance; switch to review if needed);
 *  - invites / daily activity: computed from the platform's own records.
 * A unique (mission, user, period) row makes every completion claimable once.
 */
class MissionService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly WalletService $wallet,
        private readonly ReferralService $referrals,
        private readonly TelegramClient $telegram,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function periodKey(Mission $mission): string
    {
        return $mission->repeat === 'daily' ? $this->settings->todayKey() : 'once';
    }

    /** @return Collection<int, array> */
    public function listFor(User $user): Collection
    {
        if (! $this->settings->bool('missions.enabled')) {
            return collect();
        }

        $missions = Mission::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $completions = MissionCompletion::query()
            ->where('user_id', $user->id)
            ->whereIn('mission_id', $missions->pluck('id'))
            ->whereIn('period_key', ['once', $this->settings->todayKey()])
            ->get()
            ->keyBy(fn ($c) => $c->mission_id.'|'.$c->period_key);

        return $missions->map(function (Mission $mission) use ($user, $completions) {
            $completion = $completions->get($mission->id.'|'.$this->periodKey($mission));

            return [
                'id' => $mission->id,
                'title' => $mission->title,
                'description' => $mission->description,
                'icon' => $mission->icon,
                'image_url' => $mission->image_url,
                'type' => $mission->type->value,
                'type_label' => $mission->type->label(),
                'verification' => $mission->verification->value,
                'verification_label' => $mission->verification->label(),
                'reward' => Money::format($mission->reward),
                'repeat' => $mission->repeat,
                'action_url' => $mission->actionUrl(),
                'progress' => $this->progress($user, $mission),
                'status' => $completion?->status->value ?? 'available',
                'reject_reason' => $completion?->status === CompletionStatus::Rejected ? $completion->reject_reason : null,
                'ends_at' => $mission->ends_at?->toIso8601String(),
                'needs_proof' => $mission->verification === MissionVerification::AdminReview,
            ];
        })->values();
    }

    /** Record that the user opened the mission link (starts the visit timer). */
    public function start(User $user, Mission $mission): MissionCompletion
    {
        $this->assertEligible($user, $mission);
        $period = $this->periodKey($mission);

        try {
            return MissionCompletion::query()->firstOrCreate(
                ['mission_id' => $mission->id, 'user_id' => $user->id, 'period_key' => $period],
                ['status' => CompletionStatus::Started, 'started_at' => now()]
            );
        } catch (QueryException) {
            return MissionCompletion::query()->where(['mission_id' => $mission->id, 'user_id' => $user->id, 'period_key' => $period])->firstOrFail();
        }
    }

    public function claim(User $user, Mission $mission, ?string $proof = null): MissionCompletion
    {
        $this->assertEligible($user, $mission);
        $period = $this->periodKey($mission);

        // Verification that talks to Telegram happens before taking any locks.
        if ($mission->verification === MissionVerification::TelegramApi) {
            $this->verifyTelegramMembership($user, $mission);
        }

        $completion = DB::transaction(function () use ($user, $mission, $period, $proof) {
            $mission = Mission::query()->whereKey($mission->id)->lockForUpdate()->firstOrFail();
            $completion = MissionCompletion::query()
                ->where(['mission_id' => $mission->id, 'user_id' => $user->id, 'period_key' => $period])
                ->lockForUpdate()
                ->first();

            if ($completion && in_array($completion->status, [CompletionStatus::Rewarded, CompletionStatus::PendingReview], true)) {
                throw new BusinessRuleException(
                    $completion->status === CompletionStatus::Rewarded ? 'You already completed this mission.' : 'Your submission is being reviewed.',
                    'already_claimed', 409);
            }

            $this->assertCapacity($mission);

            match ($mission->verification) {
                MissionVerification::VisitTimer => $this->verifyVisit($completion),
                MissionVerification::Automatic => $this->verifyAutomatic($user, $mission),
                default => null,
            };

            $completion ??= new MissionCompletion(['mission_id' => $mission->id, 'user_id' => $user->id, 'period_key' => $period]);

            if ($mission->verification === MissionVerification::AdminReview) {
                $proof = trim((string) $proof);
                if ($proof === '' || mb_strlen($proof) > 500) {
                    throw new BusinessRuleException('Please describe how you completed the mission (your username, a link, ...).', 'proof_required');
                }
                $completion->forceFill([
                    'status' => CompletionStatus::PendingReview,
                    'proof' => $proof,
                    'submitted_at' => now(),
                    'reward' => Money::str($mission->reward),
                    'reject_reason' => null,
                ])->save();

                return $completion;
            }

            $completion->forceFill(['status' => CompletionStatus::Started, 'submitted_at' => now()])->save();
            $this->reward($completion, $mission);

            return $completion;
        });

        $this->afterReward($user, $completion);

        return $completion->fresh();
    }

    public function approve(MissionCompletion $completion, Admin $admin): MissionCompletion
    {
        $completion = DB::transaction(function () use ($completion, $admin) {
            $completion = MissionCompletion::query()->whereKey($completion->id)->lockForUpdate()->firstOrFail();
            if ($completion->status !== CompletionStatus::PendingReview) {
                throw new BusinessRuleException('Only pending submissions can be approved.', 'invalid_state', 409);
            }
            $mission = Mission::query()->withTrashed()->whereKey($completion->mission_id)->lockForUpdate()->firstOrFail();
            $this->assertBudget($mission);

            $completion->forceFill(['reviewed_at' => now(), 'reviewed_by' => $admin->id])->save();
            $this->reward($completion, $mission);

            return $completion;
        });

        $this->audit->log('mission.approved', $completion, ['mission_id' => $completion->mission_id], $admin, $completion->user);
        $this->notifications->toUser($completion->user, 'tpl.mission_approved', [
            'mission' => $completion->mission->title,
            'amount' => Money::format($completion->reward),
        ]);
        $this->afterReward($completion->user, $completion);

        return $completion;
    }

    public function reject(MissionCompletion $completion, Admin $admin, string $reason): MissionCompletion
    {
        $completion = DB::transaction(function () use ($completion, $admin, $reason) {
            $completion = MissionCompletion::query()->whereKey($completion->id)->lockForUpdate()->firstOrFail();
            if ($completion->status !== CompletionStatus::PendingReview) {
                throw new BusinessRuleException('Only pending submissions can be rejected.', 'invalid_state', 409);
            }
            $completion->forceFill([
                'status' => CompletionStatus::Rejected,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'reject_reason' => $reason,
            ])->save();

            return $completion;
        });

        $this->audit->log('mission.rejected', $completion, ['reason' => $reason], $admin, $completion->user);
        $this->notifications->toUser($completion->user, 'tpl.mission_rejected', [
            'mission' => $completion->mission->title,
            'reason' => $reason,
        ]);

        return $completion;
    }

    public function progress(User $user, Mission $mission): ?array
    {
        return match ($mission->type) {
            MissionType::Invite => [
                'current' => min((int) $mission->target_count, $this->qualifiedReferrals($user)),
                'target' => (int) $mission->target_count,
            ],
            MissionType::DailyActivity => [
                'current' => min((int) $mission->target_count, $this->gamesToday($user)),
                'target' => (int) $mission->target_count,
            ],
            default => null,
        };
    }

    /** Close missions past their end date or out of budget. */
    public function closeFinished(): int
    {
        $closed = Mission::query()->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', now())->update(['status' => 'completed']);

        Mission::query()->where('status', 'active')->whereNotNull('budget')->get()->each(function (Mission $mission) use (&$closed) {
            if (Money::of($mission->budget_used)->plus(Money::of($mission->reward))->isGreaterThan(Money::of($mission->budget))) {
                $mission->forceFill(['status' => 'completed'])->save();
                $closed++;
            }
        });

        return $closed;
    }

    private function reward(MissionCompletion $completion, Mission $mission): void
    {
        $reward = Money::of($mission->reward);

        // If the reward budget is exhausted, the exception rolls the whole claim
        // back and the user can claim again once rewards resume.
        $entry = $reward->isPositive()
            ? $this->wallet->credit($completion->user, $reward, LedgerType::MissionReward, 'mission:'.$completion->id, $completion, 'Mission: '.$mission->title)
            : null;

        $completion->forceFill([
            'status' => CompletionStatus::Rewarded,
            'reward' => Money::str($reward),
            'ledger_entry_id' => $entry?->id,
        ])->save();

        $mission->forceFill([
            'budget_used' => Money::str(Money::of($mission->budget_used)->plus($reward)),
            'completions_count' => $mission->completions_count + 1,
        ])->save();

        if ($mission->budget !== null && Money::of($mission->budget_used)->plus($reward)->isGreaterThan(Money::of($mission->budget))) {
            $mission->forceFill(['status' => 'completed'])->save();
        }
    }

    private function afterReward(User $user, MissionCompletion $completion): void
    {
        if ($completion->status === CompletionStatus::Rewarded && $completion->ledger_entry_id) {
            $entry = LedgerEntry::query()->find($completion->ledger_entry_id);
            if ($entry) {
                $this->referrals->onRewardEarned($user, Money::of($entry->available_delta), $entry);
            }
        }
    }

    private function assertEligible(User $user, Mission $mission): void
    {
        if (! $this->settings->bool('missions.enabled')) {
            throw new BusinessRuleException('Missions are currently unavailable.', 'missions_disabled', 503);
        }
        if (! $user->isActive()) {
            throw new BusinessRuleException('Your account is restricted and cannot claim rewards.', 'account_restricted', 403);
        }
        if (! $mission->isLive()) {
            throw new BusinessRuleException('This mission is not available.', 'mission_unavailable', 404);
        }
        if ($mission->min_account_age_hours && $user->created_at->gt(now()->subHours($mission->min_account_age_hours))) {
            throw new BusinessRuleException('Your account is too new for this mission.', 'not_eligible');
        }
        if ($mission->min_games && $this->referrals->gamesPlayed($user) < $mission->min_games) {
            throw new BusinessRuleException("Play at least {$mission->min_games} games to unlock this mission.", 'not_eligible');
        }
    }

    private function assertCapacity(Mission $mission): void
    {
        if ($mission->total_limit && $mission->completions_count >= $mission->total_limit) {
            throw new BusinessRuleException('This mission has reached its completion limit.', 'mission_full', 409);
        }

        if ($mission->daily_limit) {
            $today = MissionCompletion::query()
                ->where('mission_id', $mission->id)
                ->whereIn('status', [CompletionStatus::Rewarded->value, CompletionStatus::PendingReview->value])
                ->where('updated_at', '>=', $this->settings->startOfToday())
                ->count();
            if ($today >= $mission->daily_limit) {
                throw new BusinessRuleException('This mission is full for today. Try again tomorrow.', 'mission_full_today', 409);
            }
        }

        $this->assertBudget($mission);
    }

    private function assertBudget(Mission $mission): void
    {
        if ($mission->budget !== null
            && Money::of($mission->budget_used)->plus(Money::of($mission->reward))->isGreaterThan(Money::of($mission->budget))) {
            throw new BusinessRuleException('This mission\'s reward budget is used up.', 'mission_budget', 409);
        }
    }

    private function verifyTelegramMembership(User $user, Mission $mission): void
    {
        $chat = trim((string) $mission->target);
        if (str_starts_with($chat, 'https://t.me/')) {
            $chat = '@'.trim(substr($chat, strlen('https://t.me/')), '/');
        }

        try {
            $isMember = $this->telegram->isChatMember($chat, $user->telegram_id);
        } catch (TelegramApiException $e) {
            report($e);
            throw new BusinessRuleException('We could not verify membership right now. Please try again later.', 'verification_unavailable', 503);
        }

        if (! $isMember) {
            throw new BusinessRuleException('We could not find you in the chat yet. Join first, then tap Verify.', 'not_member');
        }
    }

    private function verifyVisit(?MissionCompletion $completion): void
    {
        $minSeconds = $this->settings->int('missions.visit_min_seconds', 15);
        if (! $completion || ! $completion->started_at) {
            throw new BusinessRuleException('Open the link first, then come back to claim.', 'visit_required');
        }
        $elapsed = (int) $completion->started_at->diffInSeconds(now());
        if ($elapsed < $minSeconds) {
            throw new BusinessRuleException('Please spend a little more time on the website ('.($minSeconds - $elapsed).'s).', 'visit_too_short', 429, ['retry_after' => $minSeconds - $elapsed]);
        }
    }

    private function verifyAutomatic(User $user, Mission $mission): void
    {
        $progress = $this->progress($user, $mission);
        if ($progress === null || $progress['current'] < $progress['target'] || $progress['target'] <= 0) {
            throw new BusinessRuleException('Mission requirements are not met yet.', 'requirements_not_met');
        }
    }

    private function qualifiedReferrals(User $user): int
    {
        return User::query()->where('referrer_id', $user->id)->whereNotNull('referral_qualified_at')->count();
    }

    private function gamesToday(User $user): int
    {
        $start = $this->settings->startOfToday();

        return GameRound::query()->where('user_id', $user->id)->where('created_at', '>=', $start)->count()
            + MatchRoll::query()->where('user_id', $user->id)->where('created_at', '>=', $start)->distinct()->count('match_id');
    }
}
