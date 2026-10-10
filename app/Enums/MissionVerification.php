<?php

namespace App\Enums;

enum MissionVerification: string
{
    /** Bot API getChatMember check (bot must be an admin of the chat). */
    case TelegramApi = 'telegram_api';
    /** A moderator checks the submitted proof before any reward. */
    case AdminReview = 'admin_review';
    /** The link must have been opened via the app a minimum time before claiming. */
    case VisitTimer = 'visit_timer';
    /** Verified from the platform's own records (referrals, games played). */
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::TelegramApi => 'Telegram membership check',
            self::AdminReview => 'Manual review',
            self::VisitTimer => 'Visit timer',
            self::Automatic => 'Automatic',
        };
    }
}
