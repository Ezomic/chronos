<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Thin read-only wrapper over the Google Calendar API. No SDK: two endpoints
 * don't justify a 40MB dependency.
 */
class GoogleCalendarService implements CalendarSource
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendars(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get(self::BASE.'/users/me/calendarList');
        $response->throw();

        $items = $response->json('items', []);
        $calendars = [];

        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $calendars[] = [
                'external_id' => $item['id'],
                'name' => $item['summaryOverride'] ?? $item['summary'] ?? $item['id'],
                'color' => $item['backgroundColor'] ?? null,
                'timezone' => $item['timeZone'] ?? 'UTC',
            ];
        }

        return $calendars;
    }

    /**
     * Expanded event instances overlapping the window (singleEvents=true, so
     * recurrences come back already expanded — no RRULE handling needed).
     *
     * @return array<int, array<string, mixed>>
     */
    public function events(string $accessToken, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $response = Http::withToken($accessToken)->get(
            self::BASE.'/calendars/'.rawurlencode($calendarId).'/events',
            [
                'singleEvents' => 'true',
                'timeMin' => $from->toRfc3339String(),
                'timeMax' => $to->toRfc3339String(),
                'maxResults' => 2500,
                'orderBy' => 'startTime',
            ],
        );
        $response->throw();

        $items = $response->json('items', []);
        $events = [];

        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (! is_array($item) || ($item['status'] ?? '') === 'cancelled') {
                continue;
            }

            $keyed = [];

            foreach ($item as $key => $value) {
                $keyed[(string) $key] = $value;
            }

            $events[] = $this->normalize($keyed);
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function normalize(array $event): array
    {
        $start = is_array($event['start'] ?? null) ? $event['start'] : [];
        $end = is_array($event['end'] ?? null) ? $event['end'] : [];
        $allDay = isset($start['date']);

        if ($allDay) {
            $startDate = is_string($start['date'] ?? null) ? $start['date'] : '1970-01-01';
            $endDate = is_string($end['date'] ?? null) ? $end['date'] : '1970-01-01';
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $startDate.' 00:00', 'UTC');
            $endsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $endDate.' 00:00', 'UTC');
            $timezone = 'UTC';
        } else {
            $timezone = is_string($start['timeZone'] ?? null) ? $start['timeZone'] : 'UTC';
            $startsAt = CarbonImmutable::parse(is_string($start['dateTime'] ?? null) ? $start['dateTime'] : 'now')->utc();
            $endsAt = CarbonImmutable::parse(is_string($end['dateTime'] ?? null) ? $end['dateTime'] : 'now')->utc();
        }

        return [
            'external_id' => $event['id'],
            'external_etag' => $event['etag'] ?? null,
            'title' => $event['summary'] ?? '(no title)',
            'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'timezone' => $timezone,
        ];
    }
}
