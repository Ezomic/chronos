<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+2 weeks');
        $end = (clone $start)->modify('+1 hour');

        return [
            'calendar_id' => Calendar::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'location' => fake()->optional()->city(),
            'starts_at' => $start,
            'ends_at' => $end,
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
        ];
    }

    /**
     * A single all-day event: midnight UTC to next-day midnight UTC (exclusive).
     */
    public function allDay(): static
    {
        return $this->state(function (array $attributes) {
            $day = now()->startOfDay();

            return [
                'all_day' => true,
                'timezone' => 'UTC',
                'starts_at' => $day,
                'ends_at' => $day->copy()->addDay(),
            ];
        });
    }

    /**
     * An event mirrored from an external provider (read-only).
     */
    public function external(): static
    {
        return $this->state(fn (array $attributes) => [
            'external_id' => fake()->uuid(),
            'external_etag' => fake()->sha1(),
        ]);
    }

    /**
     * An event created from a zero email, linking back to the message.
     */
    public function fromEmail(): static
    {
        return $this->state(function (array $attributes) {
            $ulid = strtoupper(fake()->regexify('[0-9A-Z]{26}'));

            return [
                'source_app' => 'zero',
                'source_type' => 'email',
                'source_id' => $ulid,
                'source_url' => "https://zero.test/emails/ref/{$ulid}",
            ];
        });
    }
}
