<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientFundsException;
use App\Models\Admin;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Telegram\NotificationService;
use App\Support\Money;
use App\Support\TronAddress;
use App\Support\WalletMask;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Withdrawal requests with an administrator-controlled payment workflow:
 *
 *   pending ─▶ approved ─▶ processing ─▶ paid
 *      │          │            │
 *      ├─▶ cancelled (user)    │
 *      └──────────┴────────────┴─▶ rejected (funds released)
 *
 * Funds are reserved atomically on request, released exactly once on
 * rejection/cancellation and consumed exactly once when marked paid. Marking
 * "paid" records a payment the operator made; this service never moves crypto.
 */
class WithdrawalService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly WalletService $wallet,
        private readonly ReferralService $referrals,
        private readonly FraudService $fraud,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array> */
    public function networks(bool $enabledOnly = true): array
    {
        $networks = [];
        foreach ($this->settings->array('withdraw.networks') as $network) {
            if (! is_array($network) || empty($network['code'])) {
                continue;
            }
            if ($enabledOnly && ! ($network['enabled'] ?? false)) {
                continue;
            }
            $networks[] = $network + ['name' => $network['code'], 'fee_fixed' => '0', 'fee_percent' => '0'];
        }

        return $networks;
    }

    public function network(string $code): ?array
    {
        foreach ($this->networks() as $network) {
            if (strcasecmp($network['code'], $code) === 0) {
                return $network;
            }
        }

        return null;
    }

    /** @return array{min: BigDecimal, max: ?BigDecimal} */
    public function bounds(array $network): array
    {
        $min = Money::of((string) $this->settings->get('withdraw.min', '0'));
        if (isset($network['min']) && Money::of((string) $network['min'])->isGreaterThan($min)) {
            $min = Money::of((string) $network['min']);
        }

        $max = Money::of((string) $this->settings->get('withdraw.max', '0'));
        $max = $max->isPositive() ? $max : null;
        if (isset($network['max']) && Money::of((string) $network['max'])->isPositive()) {
            $netMax = Money::of((string) $network['max']);
            $max = $max === null ? $netMax : Money::min($max, $netMax);
        }

        return ['min' => $min, 'max' => $max];
    }

    /** @return array{fee: BigDecimal, net: BigDecimal} */
    public function quote(BigDecimal $amount, array $network): array
    {
        $fee = Money::of((string) ($network['fee_fixed'] ?? '0'))
            ->plus(Money::percentOf($amount, (string) ($network['fee_percent'] ?? '0')));

        return ['fee' => $fee, 'net' => $amount->minus($fee)];
    }

    public function isValidAddress(array $network, string $address): bool
    {
        $pattern = (string) ($network['address_pattern'] ?? '');
        if ($pattern !== '' && @preg_match('/'.str_replace('/', '\/', $pattern).'/', $address) !== 1) {
            return false;
        }

        if (strtoupper($network['code']) === 'TRC20') {
            return TronAddress::isValid($address);
        }

        return $pattern !== '' || preg_match('/^[A-Za-z0-9]{20,128}$/', $address) === 1;
    }

    public function request(User $user, array $data, ?string $idempotencyKey, ?string $ipHash): Withdrawal
    {
        $idempotencyKey = $idempotencyKey && preg_match('/^[A-Za-z0-9-]{8,64}$/', $idempotencyKey) ? $idempotencyKey : (string) Str::uuid();

        if ($existing = Withdrawal::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $this->assertCanWithdraw($user);

        $network = $this->network((string) $data['network']);
        if (! $network) {
            throw new BusinessRuleException('This network is not available.', 'network_unavailable');
        }

        $address = trim((string) $data['address']);
        if (! $this->isValidAddress($network, $address)) {
            throw new BusinessRuleException("This is not a valid {$network['name']} address.", 'invalid_address');
        }

        $amount = Money::of((string) $data['amount']);
        ['min' => $min, 'max' => $max] = $this->bounds($network);
        if ($amount->isLessThan($min) || ! $amount->isPositive()) {
            throw new BusinessRuleException('The minimum withdrawal is '.Money::format($min).' USDT.', 'below_minimum');
        }
        if ($max !== null && $amount->isGreaterThan($max)) {
            throw new BusinessRuleException('The maximum withdrawal is '.Money::format($max).' USDT.', 'above_maximum');
        }

        ['fee' => $fee, 'net' => $net] = $this->quote($amount, $network);
        if (! $net->isPositive()) {
            throw new BusinessRuleException('The amount does not cover the network fee.', 'below_fee');
        }

        try {
            $withdrawal = DB::transaction(function () use ($user, $data, $idempotencyKey, $ipHash, $network, $address, $amount, $fee, $net) {
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $this->assertRequestLimits($user, $amount);

                $wallet = $this->wallet->walletFor($user)->fresh();
                if (Money::of($wallet->available)->isLessThan($amount)) {
                    throw new InsufficientFundsException('You cannot withdraw more than your available balance.');
                }

                $withdrawal = Withdrawal::query()->create([
                    'reference' => $this->newReference(),
                    'user_id' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                    'full_name' => Str::limit(trim(strip_tags((string) $data['full_name'])), 120, ''),
                    'public_alias' => WalletMask::alias((string) $data['full_name']),
                    'network' => $network['code'],
                    'address' => $address,
                    'amount' => Money::str($amount),
                    'fee' => Money::str($fee),
                    'net_amount' => Money::str($net),
                    'note' => isset($data['note']) ? Str::limit(trim(strip_tags((string) $data['note'])), 500, '') : null,
                    'status' => WithdrawalStatus::Pending,
                    'ip_hash' => $ipHash,
                ]);

                $this->wallet->reserve($withdrawal);

                return $withdrawal;
            });
        } catch (QueryException $e) {
            $existing = Withdrawal::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
            throw $e;
        }

        $flags = $this->fraud->afterWithdrawalRequest($withdrawal);
        $this->audit->log('withdrawal.requested', $withdrawal, ['amount' => $withdrawal->amount, 'network' => $withdrawal->network], user: $user);

        $this->notifications->toUser($user, 'tpl.withdraw_submitted', [
            'reference' => $withdrawal->reference,
            'amount' => Money::format($withdrawal->amount),
            'network' => $withdrawal->network,
        ]);

        $reviewChannel = $this->settings->string('telegram.review_channel_id');
        if ($reviewChannel !== '') {
            $this->notifications->toChat($reviewChannel, 'tpl.withdraw_review', [
                'reference' => $withdrawal->reference,
                'amount' => Money::format($withdrawal->amount),
                'network' => $withdrawal->network,
                'user' => $user->publicId().' '.$user->handle(),
                'flags' => $flags ? implode(', ', $flags) : 'none',
            ], null, 'wd-review:'.$withdrawal->reference);
        }

        return $withdrawal;
    }

    public function approve(Withdrawal $withdrawal, Admin $admin): Withdrawal
    {
        $withdrawal = $this->transition($withdrawal, WithdrawalStatus::Approved, $admin, fn (Withdrawal $w) => $w->forceFill([
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]));

        $this->notifications->toUser($withdrawal->user, 'tpl.withdraw_approved', [
            'reference' => $withdrawal->reference,
            'amount' => Money::format($withdrawal->amount),
        ]);

        return $withdrawal;
    }

    public function markProcessing(Withdrawal $withdrawal, Admin $admin): Withdrawal
    {
        return $this->transition($withdrawal, WithdrawalStatus::Processing, $admin, fn (Withdrawal $w) => $w->forceFill([
            'processing_by' => $admin->id,
            'processing_at' => now(),
        ]));
    }

    public function markPaid(Withdrawal $withdrawal, Admin $admin, string $txHash, ?string $paymentReference): Withdrawal
    {
        $txHash = trim($txHash);
        if (! preg_match('/^[A-Za-z0-9]{16,128}$/', $txHash)) {
            throw new BusinessRuleException('Enter the transaction hash of the completed payment.', 'tx_hash_required');
        }
        if (Withdrawal::query()->where('tx_hash', $txHash)->where('id', '!=', $withdrawal->id)->exists()) {
            throw new BusinessRuleException('This transaction hash is already recorded for another withdrawal.', 'tx_hash_reused', 409);
        }

        $withdrawal = $this->transition($withdrawal, WithdrawalStatus::Paid, $admin, function (Withdrawal $w) use ($admin, $txHash, $paymentReference) {
            $w->forceFill([
                'paid_by' => $admin->id,
                'paid_at' => now(),
                'tx_hash' => $txHash,
                'payment_reference' => $paymentReference ? Str::limit(trim($paymentReference), 128, '') : null,
            ])->save();
            $this->wallet->payout($w, $admin);
        });

        $network = $this->networkByCode($withdrawal->network);
        $txDisplay = $this->explorerUrl($network, $withdrawal->tx_hash) ?? $withdrawal->tx_hash;

        $this->notifications->toUser($withdrawal->user, 'tpl.withdraw_paid', [
            'reference' => $withdrawal->reference,
            'net' => Money::format($withdrawal->net_amount),
            'network' => $withdrawal->network,
            'tx' => $txDisplay,
        ]);

        $this->publish($withdrawal);
        $this->referrals->checkQualification($withdrawal->user);

        return $withdrawal;
    }

    public function reject(Withdrawal $withdrawal, Admin $admin, string $reason): Withdrawal
    {
        $withdrawal = $this->transition($withdrawal, WithdrawalStatus::Rejected, $admin, function (Withdrawal $w) use ($admin, $reason) {
            $w->forceFill([
                'rejected_by' => $admin->id,
                'rejected_at' => now(),
                'reject_reason' => Str::limit($reason, 250, ''),
            ])->save();
            $this->wallet->release($w, $admin);
        }, ['reason' => $reason]);

        $this->notifications->toUser($withdrawal->user, 'tpl.withdraw_rejected', [
            'reference' => $withdrawal->reference,
            'amount' => Money::format($withdrawal->amount),
            'reason' => $reason,
        ]);

        return $withdrawal;
    }

    public function cancel(Withdrawal $withdrawal, User $user): Withdrawal
    {
        if ($withdrawal->user_id !== $user->id) {
            throw new BusinessRuleException('Withdrawal not found.', 'not_found', 404);
        }

        $withdrawal = DB::transaction(function () use ($withdrawal) {
            $w = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if (! $w->status->canTransitionTo(WithdrawalStatus::Cancelled)) {
                throw new BusinessRuleException('Only requests that are still pending review can be cancelled.', 'invalid_transition', 409);
            }
            $w->forceFill(['status' => WithdrawalStatus::Cancelled, 'cancelled_at' => now()])->save();
            $this->wallet->release($w);

            return $w;
        });

        $this->audit->log('withdrawal.cancelled', $withdrawal, [], user: $user);

        return $withdrawal;
    }

    /** Publish a completed payment to the public channel, once, with a masked address. */
    public function publish(Withdrawal $withdrawal): void
    {
        $channel = $this->settings->string('telegram.public_channel_id');
        if ($channel === '' || $withdrawal->status !== WithdrawalStatus::Paid || $withdrawal->published_at !== null) {
            return;
        }

        $published = Withdrawal::query()->whereKey($withdrawal->id)->whereNull('published_at')->update(['published_at' => now()]);
        if ($published === 0) {
            return;
        }

        $network = $this->networkByCode($withdrawal->network);
        $url = $this->explorerUrl($network, $withdrawal->tx_hash);

        $text = $this->notifications->render('tpl.withdraw_public', [
            'name' => $withdrawal->public_alias ?: 'User',
            'amount' => Money::format($withdrawal->net_amount),
            'network' => $this->settings->bool('telegram.public_show_network') ? ($network['name'] ?? $withdrawal->network) : '—',
            'wallet' => WalletMask::mask($withdrawal->address, $this->settings->int('telegram.mask_head', 4), $this->settings->int('telegram.mask_tail', 4)),
            'tx' => $url ? '{tx_link}' : ($withdrawal->tx_hash ? WalletMask::mask($withdrawal->tx_hash, 6, 6) : '—'),
        ]);

        if ($url) {
            $text = str_replace('{tx_link}', '<a href="'.htmlspecialchars($url, ENT_QUOTES).'">View transaction</a>', $text);
        }

        $this->notifications->queue($channel, $text, null, 'wd-public:'.$withdrawal->reference);
    }

    public function explorerUrl(?array $network, ?string $txHash): ?string
    {
        $template = (string) ($network['explorer_url'] ?? '');
        if ($template === '' || ! $txHash || ! str_starts_with($template, 'https://')) {
            return null;
        }

        return str_replace('{hash}', rawurlencode($txHash), $template);
    }

    /** Totals shown on the user's withdrawal screen. */
    public function summary(User $user): array
    {
        $wallet = $this->wallet->walletFor($user);
        $networks = array_map(function (array $n) {
            $bounds = $this->bounds($n);

            return [
                'code' => $n['code'],
                'name' => $n['name'],
                'fee_fixed' => Money::format($n['fee_fixed'] ?? '0'),
                'fee_percent' => (string) ($n['fee_percent'] ?? '0'),
                'min' => Money::format($bounds['min']),
                'max' => $bounds['max'] ? Money::format($bounds['max']) : null,
            ];
        }, $this->networks());

        return [
            'enabled' => $this->settings->bool('withdraw.enabled'),
            'available' => Money::format($wallet->available),
            'available_exact' => Money::str($wallet->available),
            'reserved' => Money::format($wallet->reserved),
            'min' => Money::format((string) $this->settings->get('withdraw.min', '0')),
            'max' => Money::format((string) $this->settings->get('withdraw.max', '0')),
            'networks' => $networks,
            'warning' => $this->settings->string('withdraw.warning_text'),
            'eligibility' => $this->eligibilityProblem($user),
        ];
    }

    public function eligibilityProblem(User $user): ?string
    {
        try {
            $this->assertCanWithdraw($user);

            return null;
        } catch (BusinessRuleException $e) {
            return $e->getMessage();
        }
    }

    private function assertCanWithdraw(User $user): void
    {
        if (! $this->settings->bool('withdraw.enabled')) {
            throw new BusinessRuleException('Withdrawals are temporarily unavailable.', 'withdrawals_disabled', 503);
        }
        if (! $user->isActive()) {
            throw new BusinessRuleException('Your account is restricted. Please contact support.', 'account_restricted', 403);
        }

        $minAge = $this->settings->int('withdraw.min_account_age_hours');
        if ($minAge > 0 && $user->created_at->gt(now()->subHours($minAge))) {
            throw new BusinessRuleException("Withdrawals unlock {$minAge} hours after you join.", 'account_too_new');
        }

        $minGames = $this->settings->int('withdraw.min_games');
        if ($minGames > 0 && $this->referrals->gamesPlayed($user) < $minGames) {
            throw new BusinessRuleException("Play at least {$minGames} games before your first withdrawal.", 'not_enough_games');
        }
    }

    private function assertRequestLimits(User $user, BigDecimal $amount): void
    {
        $open = Withdrawal::query()->where('user_id', $user->id)->whereIn('status', WithdrawalStatus::openValues())->count();
        if ($open >= $this->settings->int('withdraw.max_pending', 1)) {
            throw new BusinessRuleException('You already have a withdrawal in progress.', 'pending_exists', 409);
        }

        $today = Withdrawal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $this->settings->startOfToday())
            ->whereNotIn('status', [WithdrawalStatus::Cancelled->value, WithdrawalStatus::Rejected->value])
            ->get(['amount']);

        $maxCount = $this->settings->int('withdraw.daily_max_count');
        if ($maxCount > 0 && $today->count() >= $maxCount) {
            throw new BusinessRuleException('You reached the daily number of withdrawals.', 'daily_count');
        }

        $maxAmount = $this->settings->money('withdraw.daily_max_amount');
        $sum = $today->reduce(fn (BigDecimal $c, Withdrawal $w) => $c->plus(Money::of($w->amount)), Money::zero());
        if ($maxAmount->isPositive() && $sum->plus($amount)->isGreaterThan($maxAmount)) {
            throw new BusinessRuleException('This exceeds the daily withdrawal limit of '.Money::format($maxAmount).' USDT.', 'daily_amount');
        }
    }

    private function transition(Withdrawal $withdrawal, WithdrawalStatus $to, Admin $admin, callable $apply, array $auditData = []): Withdrawal
    {
        $from = null;

        $withdrawal = DB::transaction(function () use ($withdrawal, $to, $apply, &$from) {
            $w = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if (! $w->status->canTransitionTo($to)) {
                throw new BusinessRuleException("A {$w->status->label()} withdrawal cannot become {$to->label()}.", 'invalid_transition', 409);
            }
            $from = $w->status;
            $w->status = $to;
            $apply($w);
            $w->save();

            return $w;
        });

        $this->audit->log('withdrawal.'.$to->value, $withdrawal, $auditData + ['from' => $from->value, 'to' => $to->value], $admin, $withdrawal->user);

        return $withdrawal->fresh(['user']);
    }

    private function networkByCode(string $code): ?array
    {
        foreach ($this->networks(false) as $network) {
            if (strcasecmp($network['code'], $code) === 0) {
                return $network;
            }
        }

        return null;
    }

    private function newReference(): string
    {
        do {
            $reference = 'WD-'.strtoupper(Str::random(10));
        } while (Withdrawal::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
