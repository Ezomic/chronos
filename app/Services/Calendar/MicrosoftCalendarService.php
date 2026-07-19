<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendars(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get(self::BASE.'/me/calendars');
        $response->throw();

        $items = $response->json('value', []);
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
        $timezone = (string) config('services.microsoft.timezone', 'UTC');

        $response = Http::withToken($accessToken)
            ->withHeaders(['Prefer' => "outlook.timezone=\"{$timezone}\""])
            ->get(
                self::BASE.'/me/calendars/'.rawurlencode($calendarId).'/calendarView',
                [
                    'startDateTime' => $from->toIso8601String(),
                    'endDateTime' => $to->toIso8601String(),
                    '$top' => 1000,
                    '$orderby' => 'start/dateTime',
                ],
            );
        $response->throw();

        $items = $response->json('value', []);
        $events = [];

        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $events[] = $this->normalize($item);
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
        $location = is_array($event['location'] ?? null) ? $event['location'] : [];
        $allDay = ($event['isAllDay'] ?? false) === true;

        if ($allDay) {
            $startDate = CarbonImmutable::parse($start['dateTime'])->format('Y-m-d');
            $endDate = CarbonImmutable::parse($end['dateTime'])->format('Y-m-d');
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$startDate} 00:00", 'UTC');
            $endsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$endDate} 00:00", 'UTC');
            $timezone = 'UTC';
        } else {
            $timezone = $start['timeZone'] ?? 'UTC';
            $startsAt = CarbonImmutable::parse($start['dateTime'], $timezone)->utc();
            $endsAt = CarbonImmutable::parse($end['dateTime'], $timezone)->utc();
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
