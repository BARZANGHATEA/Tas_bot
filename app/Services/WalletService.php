<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientFundsException;
use App\Models\Admin;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The single gateway for every balance change.
 *
 * Guarantees:
 *  - each change writes exactly one immutable ledger entry in the same transaction;
 *  - the wallet row is locked (SELECT ... FOR UPDATE) for the duration;
 *  - an idempotency key makes every operation exactly-once, even when retried;
 *  - balances never go negative;
 *  - rewards are drawn from the funded reward budget.
 */
class WalletService
{
    public function __construct(private readonly BudgetService $budget) {}

    public function walletFor(User $user): Wallet
    {
        return Wallet::query()->firstOrCreate(['user_id' => $user->id]);
    }

    /** Credit a platform-funded reward. Returns the existing entry on replay. */
    public function credit(
        User $user,
        BigDecimal|string $amount,
        LedgerType $type,
        string $idempotencyKey,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
        ?Admin $admin = null,
    ): LedgerEntry {
        $amount = Money::of($amount);
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('Credit amount must be positive.');
        }
        if (! $type->isReward()) {
            throw new InvalidArgumentException("{$type->value} is not a reward type.");
        }

        return DB::transaction(function () use ($user, $amount, $type, $idempotencyKey, $reference, $description, $meta, $admin) {
            $wallet = $this->lockWallet($user);

            if ($existing = $this->existing($idempotencyKey, $user)) {
                return $existing;
            }

            $this->budget->consume($amount, $user, enforceCaps: $type !== LedgerType::AdminCredit);

            return $this->post($wallet, $type, $amount, Money::zero(), $idempotencyKey, $reference, $description, $meta, $admin,
                earnedDelta: $amount);
        });
    }

    /** Manual debit by an administrator. The amount returns to the reward budget. */
    public function adminDebit(User $user, BigDecimal|string $amount, string $reason, Admin $admin): LedgerEntry
    {
        $amount = Money::of($amount);
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('Debit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $reason, $admin) {
            $wallet = $this->lockWallet($user);
            $entry = $this->post($wallet, LedgerType::AdminDebit, $amount->negated(), Money::zero(),
                'admin-debit:'.Str::uuid(), null, $reason, ['reason' => $reason], $admin,
                earnedDelta: Money::min($amount, Money::of($wallet->total_earned))->negated());
            $this->budget->refund($amount);

            return $entry;
        });
    }

    /** Reverse a reward credit exactly once. */
    public function reverse(LedgerEntry $entry, string $reason, ?Admin $admin = null): LedgerEntry
    {
        if (! $entry->type->isReversible()) {
            throw new BusinessRuleException('This transaction type cannot be reversed.');
        }

        return DB::transaction(function () use ($entry, $reason, $admin) {
            $user = $entry->user;
            $wallet = $this->lockWallet($user);

            if (LedgerEntry::query()->where('reverses_entry_id', $entry->id)->exists()) {
                throw new BusinessRuleException('This transaction was already reversed.', 'already_reversed');
            }

            $amount = Money::of($entry->available_delta);
            if (Money::of($wallet->available)->isLessThan($amount)) {
                throw new InsufficientFundsException('The user\'s available balance is lower than the reward. Reject or wait for pending withdrawals first.');
            }

            $reversal = $this->post($wallet, LedgerType::RewardReversal, $amount->negated(), Money::zero(),
                'reversal:'.$entry->id, $entry, $reason, ['reason' => $reason, 'original_type' => $entry->type->value], $admin,
                earnedDelta: Money::min($amount, Money::of($wallet->total_earned))->negated(),
                reverses: $entry);
            $this->budget->refund($amount);

            return $reversal;
        });
    }

    /** Move funds from available to reserved for a withdrawal request. */
    public function reserve(Withdrawal $withdrawal): LedgerEntry
    {
        $amount = Money::of($withdrawal->amount);

        return DB::transaction(function () use ($withdrawal, $amount) {
            $wallet = $this->lockWallet($withdrawal->user);
            $key = 'withdrawal:'.$withdrawal->reference.':reserve';
            if ($existing = $this->existing($key, $withdrawal->user)) {
                return $existing;
            }

            return $this->post($wallet, LedgerType::WithdrawalReserve, $amount->negated(), $amount, $key, $withdrawal,
                "Withdrawal {$withdrawal->reference}");
        });
    }

    /** Return reserved funds to the available balance (rejection / cancellation). */
    public function release(Withdrawal $withdrawal, ?Admin $admin = null): LedgerEntry
    {
        $amount = Money::of($withdrawal->amount);

        return DB::transaction(function () use ($withdrawal, $amount, $admin) {
            $wallet = $this->lockWallet($withdrawal->user);
            $this->guardSingleSettlement($withdrawal, LedgerType::WithdrawalRelease);
            $key = 'withdrawal:'.$withdrawal->reference.':release';
            if ($existing = $this->existing($key, $withdrawal->user)) {
                return $existing;
            }

            return $this->post($wallet, LedgerType::WithdrawalRelease, $amount, $amount->negated(), $key, $withdrawal,
                "Withdrawal {$withdrawal->reference} returned", [], $admin);
        });
    }

    /** Consume reserved funds once the payment has really been made. */
    public function payout(Withdrawal $withdrawal, Admin $admin): LedgerEntry
    {
        $amount = Money::of($withdrawal->amount);

        return DB::transaction(function () use ($withdrawal, $amount, $admin) {
            $wallet = $this->lockWallet($withdrawal->user);
            $this->guardSingleSettlement($withdrawal, LedgerType::WithdrawalPayout);
            $key = 'withdrawal:'.$withdrawal->reference.':payout';
            if ($existing = $this->existing($key, $withdrawal->user)) {
                return $existing;
            }

            return $this->post($wallet, LedgerType::WithdrawalPayout, Money::zero(), $amount->negated(), $key, $withdrawal,
                "Withdrawal {$withdrawal->reference} paid", ['tx_hash' => $withdrawal->tx_hash], $admin,
                withdrawnDelta: $amount);
        });
    }

    /**
     * Compare every wallet with its ledger. Returns a list of discrepancies;
     * an empty list means the books balance.
     *
     * @return list<array{user_id:int, field:string, wallet:string, ledger:string}>
     */
    public function reconcile(): array
    {
        $problems = [];

        Wallet::query()->orderBy('id')->chunk(500, function ($wallets) use (&$problems) {
            $sums = LedgerEntry::query()
                ->whereIn('user_id', $wallets->pluck('user_id'))
                ->selectRaw('user_id, SUM(available_delta) as available_sum, SUM(reserved_delta) as reserved_sum')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            foreach ($wallets as $wallet) {
                $row = $sums->get($wallet->user_id);
                $ledgerAvailable = Money::of($row?->available_sum ?? '0');
                $ledgerReserved = Money::of($row?->reserved_sum ?? '0');

                if (! $ledgerAvailable->isEqualTo(Money::of($wallet->available))) {
                    $problems[] = ['user_id' => $wallet->user_id, 'field' => 'available', 'wallet' => Money::str($wallet->available), 'ledger' => Money::str($ledgerAvailable)];
                }
                if (! $ledgerReserved->isEqualTo(Money::of($wallet->reserved))) {
                    $problems[] = ['user_id' => $wallet->user_id, 'field' => 'reserved', 'wallet' => Money::str($wallet->reserved), 'ledger' => Money::str($ledgerReserved)];
                }
            }
        });

        return $problems;
    }

    private function lockWallet(User $user): Wallet
    {
        $this->walletFor($user);

        return Wallet::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    private function existing(string $key, User $user): ?LedgerEntry
    {
        $entry = LedgerEntry::query()->where('idempotency_key', $key)->first();

        if ($entry && $entry->user_id !== $user->id) {
            throw new BusinessRuleException('Idempotency key conflict.', 'idempotency_conflict', 409);
        }

        return $entry;
    }

    /** A reservation is settled exactly once: either released or paid out, never both. */
    private function guardSingleSettlement(Withdrawal $withdrawal, LedgerType $intended): void
    {
        $settled = LedgerEntry::query()
            ->whereIn('idempotency_key', [
                'withdrawal:'.$withdrawal->reference.':release',
                'withdrawal:'.$withdrawal->reference.':payout',
            ])->first();

        // Replaying the same settlement is harmless (idempotent); settling the other way is not.
        if ($settled && $settled->type !== $intended) {
            throw new BusinessRuleException('This withdrawal has already been settled.', 'already_settled', 409);
        }
    }

    private function post(
        Wallet $wallet,
        LedgerType $type,
        BigDecimal $availableDelta,
        BigDecimal $reservedDelta,
        string $idempotencyKey,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
        ?Admin $admin = null,
        ?BigDecimal $earnedDelta = null,
        ?BigDecimal $withdrawnDelta = null,
        ?LedgerEntry $reverses = null,
    ): LedgerEntry {
        $available = Money::of($wallet->available)->plus($availableDelta);
        $reserved = Money::of($wallet->reserved)->plus($reservedDelta);

        if ($available->isNegative()) {
            throw new InsufficientFundsException;
        }
        if ($reserved->isNegative()) {
            throw new BusinessRuleException('Reserved balance would become negative.', 'ledger_invariant', 409);
        }

        $entry = LedgerEntry::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $wallet->user_id,
            'type' => $type,
            'available_delta' => Money::str($availableDelta),
            'reserved_delta' => Money::str($reservedDelta),
            'available_after' => Money::str($available),
            'reserved_after' => Money::str($reserved),
            'reference_type' => $reference ? class_basename($reference) : null,
            'reference_id' => $reference?->getKey(),
            'status' => 'posted',
            'idempotency_key' => $idempotencyKey,
            'description' => $description ? Str::limit($description, 250) : null,
            'meta' => $meta ?: null,
            'admin_id' => $admin?->id,
            'reverses_entry_id' => $reverses?->id,
        ]);

        $wallet->forceFill([
            'available' => Money::str($available),
            'reserved' => Money::str($reserved),
            'total_earned' => Money::str(Money::of($wallet->total_earned)->plus($earnedDelta ?? Money::zero())),
            'total_withdrawn' => Money::str(Money::of($wallet->total_withdrawn)->plus($withdrawnDelta ?? Money::zero())),
        ])->save();

        return $entry;
    }
}
