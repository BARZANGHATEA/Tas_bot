<?php

namespace App\Services;

use App\Models\FraudFlag;
use App\Models\User;
use App\Models\Withdrawal;

/**
 * Raises review signals. Flags never punish automatically: they surface
 * patterns (bursts of sign-ups, shared networks, shared payout wallets) for
 * a human to review. No single weak signal (an IP address, for example) is
 * treated as proof of fraud.
 */
class FraudService
{
    public function __construct(private readonly Settings $settings) {}

    public function flag(User $user, string $type, string $severity = 'low', array $details = []): FraudFlag
    {
        $existing = FraudFlag::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('status', 'open')
            ->first();

        if ($existing) {
            $existing->forceFill(['details' => array_merge($existing->details ?? [], $details, ['repeated_at' => now()->toIso8601String()])])->save();

            return $existing;
        }

        $flag = FraudFlag::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'severity' => $severity,
            'details' => $details ?: null,
            'status' => 'open',
        ]);

        if (in_array($severity, ['medium', 'high'], true) && ! $user->is_flagged) {
            $user->forceFill(['is_flagged' => true])->save();
        }

        return $flag;
    }

    public function afterRegistration(User $user): void
    {
        $referrer = $user->referrer;
        if (! $referrer) {
            return;
        }

        $lastHour = User::query()
            ->where('referrer_id', $referrer->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($lastHour > $this->settings->int('referral.fraud_max_per_hour', 15)) {
            $this->flag($referrer, 'referral_velocity', 'medium', ['signups_last_hour' => $lastHour]);
        }

        if ($user->registration_ip_hash) {
            $sameNetwork = User::query()
                ->where('referrer_id', $referrer->id)
                ->where('registration_ip_hash', $user->registration_ip_hash)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($sameNetwork >= $this->settings->int('referral.fraud_shared_ip_threshold', 4)) {
                // Weak signal on its own (mobile carriers share IPs); medium only in combination.
                $this->flag($referrer, 'referral_shared_network', 'low', ['referrals_same_network_24h' => $sameNetwork]);
            }
        }
    }

    public function afterWithdrawalRequest(Withdrawal $withdrawal): array
    {
        $flags = [];

        $otherUsers = Withdrawal::query()
            ->where('address', $withdrawal->address)
            ->where('user_id', '!=', $withdrawal->user_id)
            ->distinct()
            ->pluck('user_id');

        if ($otherUsers->isNotEmpty()) {
            // Several accounts paying out to one wallet: classic multi-account farming.
            $this->flag($withdrawal->user, 'shared_payout_address', 'high', [
                'address_owner_user_ids' => $otherUsers->all(),
                'withdrawal' => $withdrawal->reference,
            ]);
            foreach (User::query()->whereIn('id', $otherUsers)->get() as $other) {
                $this->flag($other, 'shared_payout_address', 'high', ['shared_with_user_id' => $withdrawal->user_id]);
            }
            $flags[] = 'shared_payout_address';
        }

        if ($withdrawal->user->fraudFlags()->where('status', 'open')->exists()) {
            $flags[] = 'open_flags';
        }

        return $flags;
    }

    /** Periodic scan for clusters that only show up over time. */
    public function scan(): int
    {
        $raised = 0;
        $threshold = $this->settings->int('referral.fraud_shared_ip_threshold', 4);

        $clusters = User::query()
            ->whereNotNull('referrer_id')
            ->whereNotNull('registration_ip_hash')
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('referrer_id, registration_ip_hash, COUNT(*) as total')
            ->groupBy('referrer_id', 'registration_ip_hash')
            ->havingRaw('COUNT(*) >= ?', [$threshold * 2])
            ->get();

        foreach ($clusters as $cluster) {
            if ($referrer = User::query()->find($cluster->referrer_id)) {
                $this->flag($referrer, 'referral_cluster', 'medium', ['accounts_same_network_7d' => (int) $cluster->total]);
                $raised++;
            }
        }

        return $raised;
    }
}
