<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\EventTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventTemplate>
 */
class EventTemplateFactory extends Factory
{
    protected $model = EventTemplate::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'calendar_id' => Calendar::factory(),
            'name' => fake()->word().' template',
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'location' => fake()->optional()->city(),
            'all_day' => false,
            'duration_minutes' => 60,
            'default_start_time' => '09:00',
            'frequency' => null,
            'reminder_minutes' => fake()->optional()->randomElement(Event::REMINDER_CHOICES),
        ];
    }

    public function allDay(): static
    {
        return $this->state(fn (array $attributes) => [
            'all_day' => true,
            'duration_minutes' => 1440,
            'default_start_time' => null,
            'reminder_minutes' => null,
        ]);
    }
}
