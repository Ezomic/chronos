<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime;
use Sabre\VObject\Reader;

/**
 * Read-only source for a public ICS/webcal feed. Unlike the OAuth sources a
 * feed is a single calendar reachable by URL, so the CalendarSource
 * "$accessToken" argument carries the feed URL instead of a token.
 */
class IcsCalendarService implements CalendarSource
{
    /** One feed maps to one calendar; the id is stable across syncs. */
    private const CALENDAR_ID = 'feed';

    /** @var array<string, string> */
    private array $bodies = [];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calendars(string $accessToken): array
    {
        $calendar = $this->parse($accessToken);

        return [[
            'external_id' => self::CALENDAR_ID,
            'name' => $this->stringValue($this->firstProperty($calendar, 'X-WR-CALNAME')) ?? 'Subscribed calendar',
            'color' => null,
            'timezone' => $this->stringValue($this->firstProperty($calendar, 'X-WR-TIMEZONE')) ?? 'UTC',
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function events(string $accessToken, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // expand() converts every instance to UTC and drops the source TZID, so
        // capture each event's origin timezone from the un-expanded document
        // first, to store it the way the Google source does.
        $timezones = $this->originTimezones($this->parse($accessToken));

        // Expands RRULE masters into individual instances within the window and
        // drops everything outside it, mirroring Google's singleEvents=true.
        // expand() returns a new document rather than mutating in place.
        $calendar = $this->parse($accessToken)->expand($from, $to);

        $events = [];

        foreach ($calendar->select('VEVENT') as $vevent) {
            if (! $vevent instanceof VEvent) {
                continue;
            }

            $dtstart = $this->firstProperty($vevent, 'DTSTART');

            if (! $dtstart instanceof DateTime) {
                continue;
            }

            $normalized = $this->normalize($vevent, $dtstart, $timezones);

            if ($normalized['starts_at'] < $to && $normalized['ends_at'] > $from) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    private function parse(string $feedUrl): VCalendar
    {
        $body = $this->bodies[$feedUrl] ??= $this->fetch($feedUrl);

        $document = Reader::read($body, Reader::OPTION_FORGIVING);

        if (! $document instanceof VCalendar) {
            throw new RuntimeException('Feed is not a valid iCalendar document.');
        }

        return $document;
    }

    private function fetch(string $feedUrl): string
    {
        $response = Http::timeout(15)
            ->withHeaders(['User-Agent' => 'Chronos Calendar'])
            ->get($feedUrl);
        $response->throw();

        return $response->body();
    }

    /**
     * @param  array<string, string>  $timezones
     * @return array<string, mixed>
     */
    private function normalize(VEvent $event, DateTime $dtstart, array $timezones): array
    {
        $allDay = ! $dtstart->hasTime();

        [$startsAt, $timezone] = $this->resolveStart($dtstart, $allDay);
        $endsAt = $this->resolveEnd($event, $startsAt, $allDay);

        $uid = $this->stringValue($this->firstProperty($event, 'UID'));

        if (! $allDay && $uid !== null && isset($timezones[$uid])) {
            $timezone = $timezones[$uid];
        }

        $title = $this->stringValue($this->firstProperty($event, 'SUMMARY'));

        return [
            'external_id' => $this->externalId($event, $startsAt, $title),
            'external_etag' => null,
            'title' => $title ?? '(no title)',
            'description' => $this->stringValue($this->firstProperty($event, 'DESCRIPTION')),
            'location' => $this->stringValue($this->firstProperty($event, 'LOCATION')),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'timezone' => $timezone,
        ];
    }

    /**
     * A recurring feed's expanded instances all carry the master UID, so the
     * per-instance RECURRENCE-ID is what keeps them distinct on upsert.
     */
    private function externalId(VEvent $event, CarbonImmutable $startsAt, ?string $title): string
    {
        $uid = $this->stringValue($this->firstProperty($event, 'UID'));
        $recurrenceId = $this->firstProperty($event, 'RECURRENCE-ID');

        if ($uid !== null) {
            return $recurrenceId instanceof DateTime
                ? $uid.'#'.$recurrenceId->getDateTime()->format('Ymd\THis\Z')
                : $uid;
        }

        return md5($startsAt->toIso8601String().($title ?? ''));
    }

    /**
     * @return array{0: CarbonImmutable, 1: string}
     */
    private function resolveStart(DateTime $dtstart, bool $allDay): array
    {
        if ($allDay) {
            return [CarbonImmutable::parse($dtstart->getDateTime()->format('Y-m-d'), 'UTC'), 'UTC'];
        }

        $start = $dtstart->getDateTime();

        return [CarbonImmutable::instance($start)->utc(), $start->getTimezone()->getName()];
    }

    private function resolveEnd(VEvent $event, CarbonImmutable $startsAt, bool $allDay): CarbonImmutable
    {
        $dtend = $this->firstProperty($event, 'DTEND');

        if ($dtend instanceof DateTime) {
            return $allDay
                ? CarbonImmutable::parse($dtend->getDateTime()->format('Y-m-d'), 'UTC')
                : CarbonImmutable::instance($dtend->getDateTime())->utc();
        }

        $duration = $this->firstProperty($event, 'DURATION');

        if ($duration !== null) {
            $interval = DateTimeParser::parseDuration((string) $duration);

            if ($interval instanceof DateInterval) {
                return $startsAt->add($interval);
            }
        }

        return $allDay ? $startsAt->addDay() : $startsAt->addHour();
    }

    /**
     * @return array<string, string>
     */
    private function originTimezones(VCalendar $calendar): array
    {
        $timezones = [];

        foreach ($calendar->select('VEVENT') as $vevent) {
            if (! $vevent instanceof VEvent) {
                continue;
            }

            $uid = $this->stringValue($this->firstProperty($vevent, 'UID'));
            $dtstart = $this->firstProperty($vevent, 'DTSTART');

            if ($uid !== null && $dtstart instanceof DateTime && $dtstart->hasTime()) {
                $timezones[$uid] = $dtstart->getDateTime()->getTimezone()->getName();
            }
        }

        return $timezones;
    }

    private function firstProperty(Component $component, string $name): ?Property
    {
        foreach ($component->select($name) as $property) {
            if ($property instanceof Property) {
                return $property;
            }
        }

        return null;
    }

    private function stringValue(?Property $property): ?string
    {
        if ($property === null) {
            return null;
        }

        $value = trim((string) $property);

        return $value === '' ? null : $value;
    }
}
