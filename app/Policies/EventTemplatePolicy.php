<?php

namespace App\Policies;

use App\Models\EventTemplate;
use App\Models\User;

class EventTemplatePolicy
{
    public function view(User $user, EventTemplate $template): bool
    {
        return $template->user_id === $user->id;
    }

    public function update(User $user, EventTemplate $template): bool
    {
        return $template->user_id === $user->id;
    }

    public function delete(User $user, EventTemplate $template): bool
    {
        return $template->user_id === $user->id;
    }
}
