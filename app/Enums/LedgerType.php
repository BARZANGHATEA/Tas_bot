<?php

namespace App\Enums;

enum LedgerType: string
{
    case GameReward = 'game_reward';
    case MatchReward = 'match_reward';
    case ReferralReward = 'referral_reward';
    case MissionReward = 'mission_reward';
    case AdminCredit = 'admin_credit';
    case AdminDebit = 'admin_debit';
    case RewardReversal = 'reward_reversal';
    case WithdrawalReserve = 'withdrawal_reserve';
    case WithdrawalRelease = 'withdrawal_release';
    case WithdrawalPayout = 'withdrawal_payout';

    /** Credits funded from the platform reward budget. */
    public function isReward(): bool
    {
        return in_array($this, [self::GameReward, self::MatchReward, self::ReferralReward, self::MissionReward, self::AdminCredit], true);
    }

    public function isReversible(): bool
    {
        return in_array($this, [self::GameReward, self::MatchReward, self::ReferralReward, self::MissionReward, self::AdminCredit], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::GameReward => 'Dice reward',
            self::MatchReward => 'Match reward',
            self::ReferralReward => 'Referral reward',
            self::MissionReward => 'Mission reward',
            self::AdminCredit => 'Adjustment (credit)',
            self::AdminDebit => 'Adjustment (debit)',
            self::RewardReversal => 'Reward reversal',
            self::WithdrawalReserve => 'Withdrawal requested',
            self::WithdrawalRelease => 'Withdrawal returned',
            self::WithdrawalPayout => 'Withdrawal paid',
        };
    }
}
