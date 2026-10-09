<?php

namespace Database\Factories;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password-123456',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ];
    }

    public function role(AdminRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
