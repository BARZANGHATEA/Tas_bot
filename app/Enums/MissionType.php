<?php

namespace App\Enums;

enum MissionType: string
{
    case TelegramChannel = 'telegram_channel';
    case TelegramGroup = 'telegram_group';
    case Instagram = 'instagram_follow';
    case Website = 'website_visit';
    case Invite = 'invite_users';
    case DailyActivity = 'daily_activity';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::TelegramChannel => 'Join Telegram channel',
            self::TelegramGroup => 'Join Telegram group',
            self::Instagram => 'Follow Instagram account',
            self::Website => 'Visit website',
            self::Invite => 'Invite qualified friends',
            self::DailyActivity => 'Daily activity (play games)',
            self::Custom => 'Custom (admin reviewed)',
        };
    }

    /** Verification methods that are honest for this mission type. */
    public function allowedVerifications(): array
    {
        return match ($this) {
            self::TelegramChannel, self::TelegramGroup => [MissionVerification::TelegramApi, MissionVerification::AdminReview],
            // No official API lets a bot confirm an Instagram follow: always reviewed by a human.
            self::Instagram, self::Custom => [MissionVerification::AdminReview],
            self::Website => [MissionVerification::VisitTimer, MissionVerification::AdminReview],
            self::Invite, self::DailyActivity => [MissionVerification::Automatic],
        };
    }
}
