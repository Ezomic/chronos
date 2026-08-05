<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin read-only wrapper over the Microsoft Graph calendar API.
 *
 * Events are read via /calendarView, which expands recurrence into instances.
 * The plain /events endpoint does NOT expand recurrence — this is the common
 * trap. A Prefer: outlook.timezone header requests times in a configured IANA
 * zone (config services.microsoft.timezone), which Graph echoes back in
 * start.timeZone — so mirrored events keep a real local zone instead of UTC.
 */
class MicrosoftCalendarService implements CalendarSource
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    /** Runaway guard; a real mailbox never comes close to this many pages. */
    private const MAX_PAGES = 50;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendars(string $accessToken): array
    {
        $calendars = [];

        foreach ($this->pages($accessToken, self::BASE.'/me/calendars', []) as $item) {
            $calendars[] = [
                'external_id' => $item['id'],
                'name' => $item['name'] ?? $item['id'],
                'color' => $item['hexColor'] ?? null,
                'timezone' => 'UTC',
            ];
        }

        return $calendars;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function events(string $accessToken, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Ask Graph to return times in a real IANA zone (it accepts IANA names
        // and echoes them back in start.timeZone) so mirrored events keep a
        // local zone instead of the UTC default.
        $configuredTimezone = config('services.microsoft.timezone', 'UTC');
        $timezone = is_string($configuredTimezone) ? $configuredTimezone : 'UTC';

        $items = $this->pages(
            $accessToken,
            self::BASE.'/me/calendars/'.rawurlencode($calendarId).'/calendarView',
            [
                'startDateTime' => $from->toIso8601String(),
                'endDateTime' => $to->toIso8601String(),
                '$top' => 1000,
                '$orderby' => 'start/dateTime',
            ],
            ['Prefer' => "outlook.timezone=\"{$timezone}\""],
        );

        $events = [];

        foreach ($items as $item) {
            $events[] = $this->normalize($item);
        }

        return $events;
    }

    /**
     * Read every page of a Graph collection, following @odata.nextLink (an
     * absolute URL that already carries the original query) to the end. A
     * partial read matters here: the caller prunes stored events that weren't
     * in the response, so a truncated page would delete events that still
     * exist upstream. Running past the page cap therefore throws rather than
     * handing back a short list.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<int, array<string, mixed>>
     */
    private function pages(string $accessToken, string $url, array $query, array $headers = []): array
    {
        $items = [];
        $next = $url;
        $first = true;
        $pages = 0;

        do {
            $request = Http::withToken($accessToken)->withHeaders($headers);

            // A nextLink already carries the original query; passing one of our
            // own (even an empty array) would replace its skiptoken and loop.
            $response = $first ? $request->get($next, $query) : $request->get($next);
            $response->throw();

            $page = $response->json('value', []);

            if (is_array($page)) {
                foreach ($page as $item) {
                    if (is_array($item)) {
                        $items[] = $this->stringKeyed($item);
                    }
                }
            }

            // Read the body rather than json('@odata.nextLink'): data_get would
            // split that key on its dot and look for a nested "nextLink".
            $body = $response->json();
            $link = is_array($body) ? ($body['@odata.nextLink'] ?? null) : null;

            $next = is_string($link) && $link !== '' ? $link : null;
            $first = false;
        } while ($next !== null && ++$pages < self::MAX_PAGES);

        if ($next !== null) {
            throw new RuntimeException(
                'Microsoft Graph returned more than '.self::MAX_PAGES.' pages for '.$url.'; refusing a partial sync.'
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
        $location = is_array($event['location'] ?? null) ? $event['location'] : [];
        $allDay = ($event['isAllDay'] ?? false) === true;

        $startTime = is_string($start['dateTime'] ?? null) ? $start['dateTime'] : 'now';
        $endTime = is_string($end['dateTime'] ?? null) ? $end['dateTime'] : 'now';

        if ($allDay) {
            $startDate = CarbonImmutable::parse($startTime)->format('Y-m-d');
            $endDate = CarbonImmutable::parse($endTime)->format('Y-m-d');
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$startDate} 00:00", 'UTC');
            $endsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$endDate} 00:00", 'UTC');
            $timezone = 'UTC';
        } else {
            $timezone = is_string($start['timeZone'] ?? null) ? $start['timeZone'] : 'UTC';
            $startsAt = CarbonImmutable::parse($startTime, $timezone)->utc();
            $endsAt = CarbonImmutable::parse($endTime, $timezone)->utc();
        }

        return [
            'external_id' => $event['id'],
            'external_etag' => $event['@odata.etag'] ?? null,
            'title' => $event['subject'] ?? '(no title)',
            'description' => $event['bodyPreview'] ?? null,
            'location' => $location['displayName'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'timezone' => $timezone,
        ];
    }
}
