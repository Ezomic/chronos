<?php

declare(strict_types=1);

namespace App\Actions;

use App\DataObjects\EventSource;
use App\Models\Calendar;
use App\Models\Event;
use Carbon\CarbonImmutable;

class CreateEventAction
{
    /**
     * Create an event on a (writable) calendar. Times are already resolved to
     * UTC by the caller. An optional source links the event back to a row in
     * another app.
     */
    public function handle(
        Calendar $calendar,
        string $title,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $allDay,
        string $timezone,
        ?string $description = null,
        ?string $location = null,
        ?EventSource $source = null,
        ?string $rrule = null,
    ): Event {
        $event = new Event;

        $event->forceFill([
            'calendar_id' => $calendar->id,
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'timezone' => $timezone,
            'rrule' => $rrule,
            'source_app' => $source?->app,
            'source_type' => $source?->type,
            'source_id' => $source?->id,
            'source_url' => $source?->url,
        ])->save();

        return $event;
    }
}
