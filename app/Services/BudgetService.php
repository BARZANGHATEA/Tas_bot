<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Exceptions\BudgetExhaustedException;
use App\Exceptions\BusinessRuleException;
use App\Models\Admin;
use App\Models\BudgetTransaction;
use App\Models\LedgerEntry;
use App\Models\RewardBudget;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The reward budget is the amount of USDT the operator has set aside to back
 * rewards. Every reward draws from it; when it is empty, rewards stop. It is
 * an accounting control, not a crypto wallet: real funds must be held and
 * paid out by the operator separately.
 */
class BudgetService
{
    public function __construct(private readonly Settings $settings) {}

    public function current(): RewardBudget
    {
        return RewardBudget::current();
    }

    public function available(): BigDecimal
    {
        return Money::of($this->current()->balance);
    }

    /** Whether at least $amount could be issued right now (non-locking pre-check). */
    public function canIssue(BigDecimal $amount): bool
    {
        return $this->available()->isGreaterThanOrEqualTo($amount);
    }

    /**
     * Draw $amount from the budget. MUST run inside a database transaction;
     * the budget row is locked so concurrent rewards cannot overspend it.
     */
    public function consume(BigDecimal $amount, User $user, bool $enforceCaps = true): void
    {
        $budget = $this->lock();

        if (Money::of($budget->balance)->isLessThan($amount)) {
            throw new BudgetExhaustedException;
        }

        if ($enforceCaps) {
            $this->assertWithinDailyCaps($amount, $user);
        }

        $budget->balance = Money::str(Money::of($budget->balance)->minus($amount));
        $budget->total_issued = Money::str(Money::of($budget->total_issued)->plus($amount));
        $budget->save();
    }

    /** Return reversed/debited rewards to the budget. Must run inside a transaction. */
    public function refund(BigDecimal $amount): void
    {
        $budget = $this->lock();
        $budget->balance = Money::str(Money::of($budget->balance)->plus($amount));
        $budget->total_returned = Money::str(Money::of($budget->total_returned)->plus($amount));
        $budget->save();
    }

    public function fund(Admin $admin, BigDecimal $amount, ?string $note): BudgetTransaction
    {
        if (! $amount->isPositive()) {
            throw new BusinessRuleException('Amount must be positive.');
        }

        return DB::transaction(function () use ($admin, $amount, $note) {
            $budget = $this->lock();
            $budget->balance = Money::str(Money::of($budget->balance)->plus($amount));
            $budget->total_funded = Money::str(Money::of($budget->total_funded)->plus($amount));
            $budget->save();

            return BudgetTransaction::query()->create([
                'type' => 'fund',
                'amount' => Money::str($amount),
                'balance_after' => $budget->balance,
                'admin_id' => $admin->id,
                'note' => $note,
            ]);
        });
    }

    public function defund(Admin $admin, BigDecimal $amount, ?string $note): BudgetTransaction
    {
        if (! $amount->isPositive()) {
            throw new BusinessRuleException('Amount must be positive.');
        }

        return DB::transaction(function () use ($admin, $amount, $note) {
            $budget = $this->lock();
            if (Money::of($budget->balance)->isLessThan($amount)) {
                throw new BusinessRuleException('The budget does not hold that much.');
            }
            $budget->balance = Money::str(Money::of($budget->balance)->minus($amount));
            $budget->total_funded = Money::str(Money::of($budget->total_funded)->minus($amount));
            $budget->save();

            return BudgetTransaction::query()->create([
                'type' => 'defund',
                'amount' => Money::str($amount->negated()),
                'balance_after' => $budget->balance,
                'admin_id' => $admin->id,
                'note' => $note,
            ]);
        });
    }

    public function issuedToday(?User $user = null): BigDecimal
    {
        $types = array_map(fn (LedgerType $t) => $t->value, array_filter(LedgerType::cases(), fn (LedgerType $t) => $t->isReward() && $t !== LedgerType::AdminCredit));

        $query = LedgerEntry::query()
            ->whereIn('type', $types)
            ->where('created_at', '>=', $this->settings->startOfToday());

        if ($user) {
            $query->where('user_id', $user->id);
        }

        // Summed in PHP with exact decimals; daily volumes are small.
        return $query->pluck('available_delta')->reduce(
            fn (BigDecimal $carry, $value) => $carry->plus(Money::of($value)),
            Money::zero()
        );
    }

    private function assertWithinDailyCaps(BigDecimal $amount, User $user): void
    {
        $platformCap = $this->settings->money('rewards.platform_daily_cap');
        if ($platformCap->isPositive() && $this->issuedToday()->plus($amount)->isGreaterThan($platformCap)) {
            throw new BudgetExhaustedException('Today\'s reward pool is used up. Rewards resume tomorrow.', 'platform_daily_cap');
        }

        $userCap = $this->settings->money('rewards.user_daily_cap');
        if ($userCap->isPositive() && $this->issuedToday($user)->plus($amount)->isGreaterThan($userCap)) {
            throw new BudgetExhaustedException('You reached today\'s reward limit. Come back tomorrow!', 'user_daily_cap');
        }
    }

    private function lock(): RewardBudget
    {
        RewardBudget::current();

        return RewardBudget::query()->whereKey(1)->lockForUpdate()->firstOrFail();
    }
}
