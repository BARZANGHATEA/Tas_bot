<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_throttled_sign_in_returns_to_the_form_with_a_message(): void
    {
        Admin::factory()->create(['email' => 'owner@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.login.attempt'), ['email' => 'owner@example.com', 'password' => 'wrong-password'])
                ->assertRedirect();
        }

        $this->post(route('admin.login.attempt'), ['email' => 'owner@example.com', 'password' => 'wrong-password'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors(['email' => 'Too many sign-in attempts. Please wait 1 minute(s) and try again.']);
    }
}
