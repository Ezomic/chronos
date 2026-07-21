<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\EventTemplate;
use App\Models\User;

class CreateEventTemplateAction
{
    /**
     * Create a reusable event template for the user. Stores everything about an
     * event except the specific date, so a new event can prefill from it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $user, array $attributes): EventTemplate
    {
        $template = new EventTemplate;

        $template->forceFill([
            'user_id' => $user->id,
            'calendar_id' => $attributes['calendar_id'] ?? null,
            'name' => $attributes['name'],
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'location' => $attributes['location'] ?? null,
            'all_day' => $attributes['all_day'] ?? false,
            'duration_minutes' => $attributes['duration_minutes'],
            'default_start_time' => $attributes['default_start_time'] ?? null,
            'frequency' => $attributes['frequency'] ?? null,
            'reminder_minutes' => $attributes['reminder_minutes'] ?? null,
        ])->save();

        return $template;
    }
}
