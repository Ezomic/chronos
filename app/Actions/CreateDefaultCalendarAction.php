<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Calendar;
use App\Models\User;

class CreateDefaultCalendarAction
{
    /**
     * Ensure the user has a default, writable local calendar for events to
     * land in. Idempotent — never creates a second default.
     */
    public function handle(User $user): Calendar
    {
        return Calendar::query()->firstOrCreate(
            ['user_id' => $user->id, 'is_default' => true],
            [
                'name' => 'Personal',
                'color' => Calendar::COLOR_PALETTE[0],
                'timezone' => config('app.timezone'),
                'is_writable' => true,
                'is_visible' => true,
            ],
        );
    }
}
