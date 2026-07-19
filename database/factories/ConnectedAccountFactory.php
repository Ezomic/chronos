<?php

namespace Database\Factories;

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectedAccount>
 */
class ConnectedAccountFactory extends Factory
{
    protected $model = ConnectedAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => ConnectedAccount::PROVIDER_GOOGLE,
            'email_address' => fake()->unique()->safeEmail(),
            'display_name' => fake()->name(),
            'oauth_access_token' => 'access-'.fake()->uuid(),
            'oauth_refresh_token' => 'refresh-'.fake()->uuid(),
            'oauth_expires_at' => now()->addHour(),
            'sync_status' => 'idle',
            'is_active' => true,
        ];
    }

    public function microsoft(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => ConnectedAccount::PROVIDER_MICROSOFT,
        ]);
    }
}
