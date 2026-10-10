<?php

namespace App\Services;

use App\Enums\CompletionStatus;
use App\Enums\LedgerType;
use App\Enums\MatchStatus;
use App\Enums\WithdrawalStatus;
use App\Models\FraudFlag;
use App\Models\GameMatch;
use App\Models\GameRound;
use App\Models\LedgerEntry;
use App\Models\MissionCompletion;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/** Read-only aggregates for the Mini App home screen and the admin dashboard. */
class StatsService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly WalletService $wallet,
        private readonly BudgetService $budget,
    ) {}

    public function playerSummary(User $user): array
    {
        $wallet = $this->wallet->walletFor($user);

        $singleRounds = GameRound::query()->where('user_id', $user->id)->count();
        $singleWins = GameRound::query()->where('user_id', $user->id)->where('is_win', true)->count();
        $matches = GameMatch::query()
            ->where('status', MatchStatus::Completed)
            ->where(fn ($q) => $q->where('creator_id', $user->id)->orWhere('opponent_id', $user->id));
        $matchCount = (clone $matches)->count();
        $matchWins = (clone $matches)->where('winner_id', $user->id)->count();

        $pendingRewards = MissionCompletion::query()
            ->where('user_id', $user->id)
            ->where('status', CompletionStatus::PendingReview)
            ->pluck('reward')
            ->reduce(fn (BigDecimal $c, $v) => $c->plus(Money::of($v)), Money::zero());

        return [
            'available' => Money::format($wallet->available),
            'reserved' => Money::format($wallet->reserved),
            'pending_rewards' => Money::format($pendingRewards),
            'total_earned' => Money::format($wallet->total_earned),
            'total_withdrawn' => Money::format($wallet->total_withdrawn),
            'games_played' => $singleRounds + $matchCount,
            'games_won' => $singleWins + $matchWins,
            'referrals' => User::query()->where('referrer_id', $user->id)->count(),
        ];
    }

    public function recentTransactions(User $user, int $limit = 10): array
    {
        return LedgerEntry::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (LedgerEntry $e) => $this->presentEntry($e))
            ->all();
    }

    public function presentEntry(LedgerEntry $entry): array
    {
        $available = Money::of($entry->available_delta);
        $reserved = Money::of($entry->reserved_delta);
        // What the user perceives: withdrawal payouts reduce "reserved", show as outgoing.
        $display = $entry->type === LedgerType::WithdrawalPayout ? $reserved : $available;

        return [
            'id' => $entry->uuid,
            'type' => $entry->type->value,
            'label' => $entry->type->label(),
            'description' => $entry->description,
            'amount' => ($display->isNegative() ? '-' : '+').Money::format($display->abs()),
            'direction' => $display->isNegative() ? 'out' : 'in',
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }

    public function dashboard(): array
    {
        $today = $this->settings->startOfToday();
        $budget = $this->budget->current();

        $sum = fn ($query, string $column) => $query->pluck($column)->reduce(fn (BigDecimal $c, $v) => $c->plus(Money::of($v)), Money::zero());

        $rewardTypes = [LedgerType::GameReward->value, LedgerType::MatchReward->value, LedgerType::ReferralReward->value, LedgerType::MissionReward->value];

        $rounds = GameRound::query()->count();
        $wins = GameRound::query()->where('is_win', true)->count();

        return [
            'users_total' => User::query()->count(),
            'users_today' => User::query()->where('created_at', '>=', $today)->count(),
            'users_active_24h' => User::query()->where('last_seen_at', '>=', now()->subDay())->count(),
            'users_active_7d' => User::query()->where('last_seen_at', '>=', now()->subDays(7))->count(),
            'rounds_total' => $rounds,
            'rounds_today' => GameRound::query()->where('created_at', '>=', $today)->count(),
            'wins_total' => $wins,
            'losses_total' => $rounds - $wins,
            'win_rate' => $rounds > 0 ? round($wins / $rounds * 100, 1) : 0,
            'matches_completed' => GameMatch::query()->where('status', MatchStatus::Completed)->count(),
            'matches_open' => GameMatch::query()->whereIn('status', MatchStatus::openValues())->count(),
            'rewards_total' => Money::format($sum(LedgerEntry::query()->whereIn('type', $rewardTypes), 'available_delta')),
            'rewards_today' => Money::format($this->budget->issuedToday()),
            'referred_users' => User::query()->whereNotNull('referrer_id')->count(),
            'qualified_referrals' => User::query()->whereNotNull('referral_qualified_at')->count(),
            'referral_rewards' => Money::format($sum(ReferralReward::query()->where('status', 'credited'), 'amount')),
            'missions_completed' => MissionCompletion::query()->where('status', CompletionStatus::Rewarded)->count(),
            'missions_pending' => MissionCompletion::query()->where('status', CompletionStatus::PendingReview)->count(),
            'withdrawals_pending' => Withdrawal::query()->where('status', WithdrawalStatus::Pending)->count(),
            'withdrawals_open' => Withdrawal::query()->whereIn('status', WithdrawalStatus::openValues())->count(),
            'withdrawals_open_amount' => Money::format($sum(Withdrawal::query()->whereIn('status', WithdrawalStatus::openValues()), 'amount')),
            'withdrawals_paid_amount' => Money::format($sum(Withdrawal::query()->where('status', WithdrawalStatus::Paid), 'net_amount')),
            'withdrawals_paid_count' => Withdrawal::query()->where('status', WithdrawalStatus::Paid)->count(),
            'budget_balance' => Money::format($budget->balance),
            'budget_low' => Money::of($budget->balance)->isLessThan($this->settings->money('rewards.low_budget_alert')),
            'fraud_open' => FraudFlag::query()->where('status', 'open')->count(),
            'fraud_high' => FraudFlag::query()->where('status', 'open')->whereIn('severity', ['medium', 'high'])->count(),
            'registrations' => $registrations = $this->dailySeries(User::query(), 14),
            'rounds_series' => $rounds = $this->dailySeries(GameRound::query(), 14),
            'users_yesterday' => $registrations[count($registrations) - 2]['count'] ?? 0,
            'rounds_yesterday' => $rounds[count($rounds) - 2]['count'] ?? 0,
            'rewards_yesterday' => Money::format($this->rewardsBetween($today->subDay(), $today)),
            'rewards_today_raw' => (string) $this->budget->issuedToday(),
            'platform_cap' => (string) $this->settings->money('rewards.platform_daily_cap'),
            'withdrawals_pending_amount' => Money::format($sum(Withdrawal::query()->where('status', WithdrawalStatus::Pending), 'amount')),
        ];
    }

    /** Platform-funded rewards issued in [from, to) – same definition as the daily cap. */
    private function rewardsBetween(\DateTimeInterface $from, \DateTimeInterface $to): BigDecimal
    {
        $types = [LedgerType::GameReward->value, LedgerType::MatchReward->value, LedgerType::ReferralReward->value, LedgerType::MissionReward->value];

        return LedgerEntry::query()->whereIn('type', $types)->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->pluck('available_delta')->reduce(fn (BigDecimal $c, $v) => $c->plus(Money::of($v)), Money::zero());
    }

    /**
     * One indexed range count per day: portable across MySQL/MariaDB/SQLite and
     * correct for the configured business time zone.
     *
     * @return list<array{date: string, count: int}>
     */
    private function dailySeries($query, int $days): array
    {
        $start = CarbonImmutable::now($this->settings->timezone())->subDays($days - 1)->startOfDay();

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $from = $start->addDays($i);
            $series[] = [
                'date' => $from->format('M j'),
                'count' => (clone $query)->where('created_at', '>=', $from->utc())->where('created_at', '<', $from->addDay()->utc())->count(),
            ];
        }

        return $series;
    }
}
