<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;

interface CalendarSource
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendars(string $accessToken): array;

    /**
     * Expanded event instances overlapping the window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function events(string $accessToken, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array;
}
