<?php

namespace App\Policies;

use App\Models\Calendar;
use App\Models\User;

class CalendarPolicy
{
    public function view(User $user, Calendar $calendar): bool
    {
        return $calendar->user_id === $user->id;
    }

    /**
     * Only local calendars are writable; mirrored ones are read-only.
     */
    public function update(User $user, Calendar $calendar): bool
    {
        return $calendar->user_id === $user->id
            && $calendar->is_writable;
    }

    public function delete(User $user, Calendar $calendar): bool
    {
        return $this->update($user, $calendar);
    }
}
