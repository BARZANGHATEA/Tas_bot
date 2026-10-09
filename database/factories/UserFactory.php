<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'telegram_id' => fake()->unique()->numberBetween(10_000_000, 9_000_000_000),
            'username' => fake()->unique()->regexify('[a-z]{6}[0-9]{3}'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'referral_code' => User::generateReferralCode(),
            'status' => UserStatus::Active,
            'registration_source' => 'bot',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => Wallet::query()->firstOrCreate(['user_id' => $user->id]));
    }

    public function newcomer(): static
    {
        return $this->state(fn () => ['created_at' => now(), 'updated_at' => now()]);
    }

    public function referredBy(User $referrer): static
    {
        return $this->state(fn () => ['referrer_id' => $referrer->id, 'referred_at' => now()]);
    }
}
