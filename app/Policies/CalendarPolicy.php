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
        // The provisioned default calendar can't be removed; events must
        // always have a writable calendar to land in.
        return $this->update($user, $calendar)
            && ! $calendar->is_default;
    }

    /**
     * Visibility is a per-user display preference, so it can be toggled on any
     * owned calendar, including read-only mirrored ones.
     */
    public function changeVisibility(User $user, Calendar $calendar): bool
    {
        return $calendar->user_id === $user->id;
    }
}
