<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Carbon\CarbonImmutable;

trait ResolvesEventTimes
{
    /**
     * Resolve local date/time inputs to UTC storage values. All-day events
     * become an exclusive midnight-UTC span with a floating 'UTC' zone; timed
     * events are parsed in their zone and converted to UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    protected function resolveEventTimes(bool $allDay, ?string $timezone, string $start, string $end): array
    {
        if ($allDay) {
            $startDate = CarbonImmutable::parse($start)->format('Y-m-d');
            $endDate = CarbonImmutable::parse($end)->format('Y-m-d');

            return [
                CarbonImmutable::parse("{$startDate} 00:00", 'UTC'),
                CarbonImmutable::parse("{$endDate} 00:00", 'UTC')->addDay(),
                'UTC',
            ];
        }

        $timezone = $timezone ?: $this->fallbackTimezone();

        return [
            CarbonImmutable::parse($start, $timezone)->utc(),
            CarbonImmutable::parse($end, $timezone)->utc(),
            $timezone,
        ];
    }

    /**
     * The zone to store an event in when the request did not name one. The
     * user's own setting comes first: app.timezone is UTC, and the calendar
     * renders every event in its stored zone, so falling back to config would
     * draw an event posted by another app an hour or two out of place.
     */
    private function fallbackTimezone(): string
    {
        $user = auth()->user();

        if ($user instanceof User && $user->timezone !== '') {
            return $user->timezone;
        }

        $configured = config('app.timezone');

        return is_string($configured) ? $configured : 'UTC';
    }
}
