<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Exceptions\BudgetExhaustedException;
use App\Models\Admin;
use App\Models\GameRound;
use App\Models\LedgerEntry;
use App\Models\MatchRoll;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Telegram\NotificationService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Multi-level referral program.
 *
 *  - The inviter is fixed once at registration and never reassigned.
 *  - A "qualification" bonus is paid to each upline level once, when the
 *    referred user meets the qualification rules (games played, account age).
 *  - A "commission" (percentage) is paid on the referred user's game and
 *    mission rewards, funded by the platform budget, never taken from the user.
 *  - Every payout has a unique idempotency key, so retries can't duplicate it.
 */
class ReferralService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** Resolve a referral start parameter ("ref_CODE") to an eligible inviter. */
    public function resolveReferrer(?string $startParam, int $newTelegramId): ?User
    {
        if ($startParam === null || ! preg_match('/^ref_([A-Z2-9]{8})$/', strtoupper($startParam), $m)) {
            return null;
        }

        $referrer = User::query()->where('referral_code', $m[1])->first();

        if (! $referrer || $referrer->telegram_id === $newTelegramId || $referrer->isSuspended()) {
            return null;
        }

        return $referrer;
    }

    /** Upline: [level => User], level 1 = direct inviter. Cycle-safe. */
    public function ancestors(User $user, ?int $maxDepth = null): array
    {
        $maxDepth ??= $this->settings->int('referral.max_depth', 3);
        $chain = [];
        $seen = [$user->id => true];
        $current = $user;

        for ($level = 1; $level <= $maxDepth; $level++) {
            if (! $current->referrer_id || isset($seen[$current->referrer_id])) {
                break;
            }
            $parent = User::query()->find($current->referrer_id);
            if (! $parent) {
                break;
            }
            $chain[$level] = $parent;
            $seen[$parent->id] = true;
            $current = $parent;
        }

        return $chain;
    }

    public function isCampaignActive(): bool
    {
        if (! $this->settings->bool('referral.enabled')) {
            return false;
        }

        $now = CarbonImmutable::now();
        $start = $this->settings->get('referral.campaign_starts_at');
        $end = $this->settings->get('referral.campaign_ends_at');

        if ($start && $now->lt(CarbonImmutable::parse($start, $this->settings->timezone()))) {
            return false;
        }
        if ($end && $now->gt(CarbonImmutable::parse($end, $this->settings->timezone()))) {
            return false;
        }

        return true;
    }

    /** @return array<int, array{fixed: BigDecimal, percent: BigDecimal}> */
    public function levelRules(): array
    {
        $rules = [];
        $depth = $this->settings->int('referral.max_depth', 3);
        foreach (array_values($this->settings->array('referral.levels')) as $i => $level) {
            if ($i + 1 > $depth) {
                break;
            }
            $rules[$i + 1] = [
                'fixed' => Money::of((string) ($level['fixed'] ?? '0')),
                'percent' => BigDecimal::of((string) ($level['percent'] ?? '0')),
            ];
        }

        return $rules;
    }

    public function gamesPlayed(User $user): int
    {
        return GameRound::query()->where('user_id', $user->id)->count()
            + MatchRoll::query()->where('user_id', $user->id)->distinct()->count('match_id');
    }

    public function meetsQualification(User $user): bool
    {
        if (! $user->isActive() || $user->is_flagged) {
            return false;
        }

        $minAge = $this->settings->int('referral.qualify_min_age_hours', 24);
        if ($user->created_at->gt(now()->subHours($minAge))) {
            return false;
        }

        return $this->gamesPlayed($user) >= $this->settings->int('referral.qualify_min_games', 10);
    }

    /**
     * Mark the user qualified (once) and pay the one-time bonus to the upline.
     * Safe to call on every activity: it is a no-op after the first success.
     */
    public function checkQualification(User $user): void
    {
        if ($user->referral_qualified_at !== null || $user->referrer_id === null) {
            return;
        }
        if (! $this->meetsQualification($user)) {
            return;
        }

        $qualifiedNow = DB::transaction(function () use ($user) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($locked->referral_qualified_at !== null) {
                return false;
            }
            $locked->forceFill(['referral_qualified_at' => now()])->save();
            $user->referral_qualified_at = $locked->referral_qualified_at;

            return true;
        });

        if (! $qualifiedNow || ! $this->isCampaignActive()) {
            return;
        }

        $rules = $this->levelRules();
        foreach ($this->ancestors($user) as $level => $beneficiary) {
            $fixed = $rules[$level]['fixed'] ?? Money::zero();
            if ($fixed->isPositive()) {
                $this->pay($beneficiary, $user, $level, 'qualification', $fixed, null, null, "ref:q:{$user->id}:{$level}");
            }
        }
    }

    /** Commission on a reward the referred user just earned (source = its ledger entry). */
    public function onRewardEarned(User $earner, BigDecimal $amount, LedgerEntry $source): void
    {
        if (! $amount->isPositive() || $earner->referrer_id === null || $earner->referral_qualified_at === null) {
            return;
        }
        if (! $this->isCampaignActive()) {
            return;
        }

        $rules = $this->levelRules();
        $sourceType = class_basename($source);

        foreach ($this->ancestors($earner) as $level => $beneficiary) {
            $percent = $rules[$level]['percent'] ?? BigDecimal::zero();
            if (! $percent->isPositive()) {
                continue;
            }
            $commission = Money::percentOf($amount, $percent);
            if ($commission->isPositive()) {
                $this->pay($beneficiary, $earner, $level, 'commission', $commission, $amount, $source,
                    "ref:c:{$sourceType}:{$source->getKey()}:{$level}");
            }
        }
    }

    public function reverse(ReferralReward $reward, string $reason, Admin $admin): ReferralReward
    {
        return DB::transaction(function () use ($reward, $reason, $admin) {
            $reward = ReferralReward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();
            if ($reward->status !== 'credited' || ! $reward->ledgerEntry) {
                throw new \App\Exceptions\BusinessRuleException('Only credited rewards can be reversed.');
            }

            $this->wallet->reverse($reward->ledgerEntry, 'Referral reward reversed: '.$reason, $admin);
            $reward->forceFill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => $admin->id,
                'reverse_reason' => $reason,
            ])->save();

            $this->audit->log('referral.reward_reversed', $reward, ['reason' => $reason, 'amount' => $reward->amount], $admin, $reward->beneficiary);

            return $reward;
        });
    }

    private function pay(User $beneficiary, User $source, int $level, string $event, BigDecimal $amount, ?BigDecimal $base, ?Model $sourceModel, string $key): void
    {
        if (ReferralReward::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        $status = 'credited';
        $skipReason = null;
        $entry = null;

        // Restricted/suspended inviters don't earn; the event is still recorded once.
        if (! $beneficiary->isActive()) {
            $status = 'skipped';
            $skipReason = 'beneficiary_'.$beneficiary->status->value;
        }

        try {
            DB::transaction(function () use ($beneficiary, $source, $level, $event, $amount, $base, $sourceModel, $key, &$status, &$skipReason, &$entry) {
                $reward = ReferralReward::query()->create([
                    'beneficiary_id' => $beneficiary->id,
                    'source_user_id' => $source->id,
                    'level' => $level,
                    'event' => $event,
                    'source_type' => $sourceModel ? class_basename($sourceModel) : null,
                    'source_id' => $sourceModel?->getKey(),
                    'base_amount' => $base ? Money::str($base) : null,
                    'amount' => Money::str($amount),
                    'status' => $status,
                    'skip_reason' => $skipReason,
                    'idempotency_key' => $key,
                ]);

                if ($status !== 'credited') {
                    return;
                }

                try {
                    $entry = DB::transaction(fn () => $this->wallet->credit(
                        $beneficiary, $amount, LedgerType::ReferralReward, $key, $reward,
                        "Level {$level} referral {$event} from {$source->publicId()}",
                        ['level' => $level, 'event' => $event, 'source_user_id' => $source->id],
                    ));
                    $reward->forceFill(['ledger_entry_id' => $entry->id])->save();
                } catch (BudgetExhaustedException $e) {
                    $status = 'skipped';
                    $reward->forceFill(['status' => 'skipped', 'skip_reason' => $e->errorCode])->save();
                }
            });
        } catch (QueryException $e) {
            // Unique key hit by a concurrent duplicate: already handled once.
            if (ReferralReward::query()->where('idempotency_key', $key)->exists()) {
                return;
            }
            Log::error('Referral reward failed', ['key' => $key, 'error' => $e->getMessage()]);

            return;
        }

        if ($status === 'credited' && $entry) {
            $this->notifications->toUser($beneficiary, 'tpl.reward', [
                'amount' => Money::format($amount),
                'friend' => $level === 1 ? $source->displayName() : $source->publicId(),
                'level' => $level,
            ]);
        }
    }
}
