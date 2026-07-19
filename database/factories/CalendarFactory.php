<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Calendar>
 */
class CalendarFactory extends Factory
{
    protected $model = Calendar::class;

    /**
     * A local, writable calendar by default.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'connected_account_id' => null,
            'name' => fake()->word().' calendar',
            'color' => fake()->randomElement(Calendar::COLOR_PALETTE),
            'timezone' => 'Europe/Amsterdam',
            'external_id' => null,
            'is_default' => false,
            'is_visible' => true,
            'is_writable' => true,
            'synced_at' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
            'name' => 'Personal',
        ]);
    }

    /**
     * A read-only calendar mirrored from a connected external account.
     */
    public function mirrored(): static
    {
        return $this->state(fn (array $attributes) => [
            'connected_account_id' => ConnectedAccount::factory(),
            'external_id' => fake()->uuid(),
            'is_writable' => false,
            'synced_at' => now(),
        ]);
    }
}
