<?php

namespace Database\Factories;

use App\Enums\MissionType;
use App\Enums\MissionVerification;
use App\Models\Mission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mission>
 */
class MissionFactory extends Factory
{
    protected $model = Mission::class;

    public function definition(): array
    {
        return [
            'title' => 'Join our channel',
            'description' => 'Join the official channel for news.',
            'icon' => '📣',
            'type' => MissionType::TelegramChannel,
            'target' => '@dice_news',
            'verification' => MissionVerification::TelegramApi,
            'reward' => '0.20',
            'repeat' => 'once',
            'status' => 'active',
        ];
    }
}
