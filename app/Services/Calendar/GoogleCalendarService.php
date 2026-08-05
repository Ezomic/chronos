<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin read-only wrapper over the Google Calendar API. No SDK: two endpoints
 * don't justify a 40MB dependency.
 */
class GoogleCalendarService implements CalendarSource
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    /** Runaway guard; a real calendar never comes close to this many pages. */
    private const MAX_PAGES = 50;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendars(string $accessToken): array
    {
        $calendars = [];

        foreach ($this->pages($accessToken, self::BASE.'/users/me/calendarList', []) as $item) {
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
        $items = $this->pages(
            $accessToken,
            self::BASE.'/calendars/'.rawurlencode($calendarId).'/events',
            [
                'singleEvents' => 'true',
                'timeMin' => $from->toRfc3339String(),
                'timeMax' => $to->toRfc3339String(),
                'maxResults' => 2500,
                'orderBy' => 'startTime',
            ],
        );

        $events = [];

        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'cancelled') {
                continue;
            }

            $events[] = $this->normalize($item);
        }

        return $events;
    }

    /**
     * Read every page of a Google collection, following nextPageToken to the
     * end. A partial read matters here: the caller prunes stored events that
     * weren't in the response, so a truncated page would delete events that
     * still exist upstream. Running past the page cap therefore throws rather
     * than handing back a short list.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function pages(string $accessToken, string $url, array $query): array
    {
        $items = [];
        $pageToken = null;
        $pages = 0;

        do {
            $response = Http::withToken($accessToken)->get(
                $url,
                $pageToken === null ? $query : array_merge($query, ['pageToken' => $pageToken]),
            );
            $response->throw();

            $page = $response->json('items', []);

            if (is_array($page)) {
                foreach ($page as $item) {
                    if (is_array($item)) {
                        $items[] = $this->stringKeyed($item);
                    }
                }
            }

            $next = $response->json('nextPageToken');
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && ++$pages < self::MAX_PAGES);

        if ($pageToken !== null) {
            throw new RuntimeException(
                'Google returned more than '.self::MAX_PAGES.' pages for '.$url.'; refusing a partial sync.'
            );
        }

        return $items;
    }

    /**
     * @param  array<mixed, mixed>  $item
     * @return array<string, mixed>
     */
    private function stringKeyed(array $item): array
    {
        $keyed = [];

        foreach ($item as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
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
