<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function view(User $user, Event $event): bool
    {
        return $event->calendar->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Mirrored calendars are read-only, so an event can only be changed when
     * it lives on the user's own writable calendar.
     */
    public function update(User $user, Event $event): bool
    {
        return $event->calendar->user_id === $user->id
            && $event->calendar->is_writable;
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->update($user, $event);
    }
}
