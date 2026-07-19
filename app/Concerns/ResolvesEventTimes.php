<?php

declare(strict_types=1);

namespace App\Concerns;

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
                CarbonImmutable::createFromFormat('Y-m-d H:i', "{$startDate} 00:00", 'UTC'),
                CarbonImmutable::createFromFormat('Y-m-d H:i', "{$endDate} 00:00", 'UTC')->addDay(),
                'UTC',
            ];
        }

        $timezone = $timezone ?: config('app.timezone');

        return [
            CarbonImmutable::parse($start, $timezone)->utc(),
            CarbonImmutable::parse($end, $timezone)->utc(),
            $timezone,
        ];
    }
}
