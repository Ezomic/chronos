<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\BookingType;
use App\Models\User;

class EnsureBookingTypeAction
{
    public const DEFAULT_SLUG = 'intro-call';

    /**
     * The owner's booking type, created with the agreed defaults the first
     * time anyone asks for it. Idempotent.
     */
    public function handle(User $owner): BookingType
    {
        $type = BookingType::query()->firstOrCreate(
            ['user_id' => $owner->id, 'slug' => self::DEFAULT_SLUG],
            [
                'name' => 'Intro call',
                'duration_minutes' => 20,
                'slot_step_minutes' => 30,
                'buffer_minutes' => 15,
                'min_notice_minutes' => 24 * 60,
                'max_days_ahead' => 30,
                'timezone' => 'Europe/Amsterdam',
                'is_active' => true,
            ],
        );

        if ($type->wasRecentlyCreated) {
            $type->windows()->createMany(array_map(
                fn (int $weekday): array => ['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00'],
                [1, 2, 3, 4, 5],
            ));
        }

        return $type;
    }
}
