<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Calendar;
use App\Models\User;

class CreateCalendarAction
{
    /**
     * Create a local, writable calendar for the user. Local calendars are
     * never the default (only the provisioned "Personal" calendar is) and
     * never mirror an external account.
     */
    public function handle(User $user, string $name, string $color): Calendar
    {
        return Calendar::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'color' => $color,
            'timezone' => config('app.timezone'),
            'is_default' => false,
            'is_writable' => true,
            'is_visible' => true,
        ]);
    }
}
