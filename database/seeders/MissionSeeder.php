<?php

namespace Database\Seeders;

use App\Enums\MissionType;
use App\Enums\MissionVerification;
use App\Models\Mission;
use Illuminate\Database\Seeder;

/**
 * Example missions. Those that need a real target (channel, website, ...) are
 * created paused so nothing goes live until an administrator configures them.
 */
class MissionSeeder extends Seeder
{
    public function run(): void
    {
        $missions = [
            ['title' => 'Daily roller', 'description' => 'Play 5 games today.', 'icon' => '🎲', 'type' => MissionType::DailyActivity, 'verification' => MissionVerification::Automatic, 'target_count' => 5, 'reward' => '0.02', 'repeat' => 'daily', 'status' => 'active', 'sort_order' => 1],
            ['title' => 'Bring 3 friends', 'description' => 'Invite 3 friends who qualify (play and stay active).', 'icon' => '👥', 'type' => MissionType::Invite, 'verification' => MissionVerification::Automatic, 'target_count' => 3, 'reward' => '0.30', 'repeat' => 'once', 'status' => 'active', 'sort_order' => 2],
            ['title' => 'Join our channel', 'description' => 'Join the official news channel. The bot must be an admin of the channel to verify.', 'icon' => '📣', 'type' => MissionType::TelegramChannel, 'verification' => MissionVerification::TelegramApi, 'target' => '@your_channel', 'reward' => '0.10', 'repeat' => 'once', 'status' => 'paused', 'sort_order' => 3],
            ['title' => 'Join the community', 'description' => 'Join our Telegram group.', 'icon' => '💬', 'type' => MissionType::TelegramGroup, 'verification' => MissionVerification::TelegramApi, 'target' => '@your_group', 'reward' => '0.10', 'repeat' => 'once', 'status' => 'paused', 'sort_order' => 4],
            ['title' => 'Follow us on Instagram', 'description' => 'Follow our account and send your Instagram username for review.', 'icon' => '📸', 'type' => MissionType::Instagram, 'verification' => MissionVerification::AdminReview, 'target' => 'your_instagram', 'reward' => '0.10', 'repeat' => 'once', 'status' => 'paused', 'sort_order' => 5],
            ['title' => 'Visit our website', 'description' => 'Open the website and look around.', 'icon' => '🌐', 'type' => MissionType::Website, 'verification' => MissionVerification::VisitTimer, 'target' => 'https://example.com', 'reward' => '0.02', 'repeat' => 'once', 'status' => 'paused', 'sort_order' => 6],
        ];

        foreach ($missions as $mission) {
            Mission::query()->firstOrCreate(['title' => $mission['title']], $mission);
        }
    }
}
