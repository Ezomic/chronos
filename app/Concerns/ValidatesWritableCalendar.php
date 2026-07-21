<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait ValidatesWritableCalendar
{
    use InteractsWithCurrentUser;

    /**
     * A `calendar_id` must point at one of the current user's own writable
     * (local) calendars; mirrored calendars are read-only.
     */
    protected function writableCalendarRule(): Exists
    {
        return Rule::exists('calendars', 'id')->where(fn ($query) => $query
            ->where('user_id', $this->currentUser()->id)
            ->where('is_writable', true));
    }
}
