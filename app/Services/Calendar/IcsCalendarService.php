<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Http\Client\Response;
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

    /** Enough for a very large public feed, far short of exhausting memory. */
    private const MAX_BYTES = 5_242_880;

    private const MAX_REDIRECTS = 3;

    /** @var array<string, string> */
    private array $bodies = [];

    public function __construct(private readonly FeedUrlGuard $guard) {}

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

    /**
     * Fetch a feed without letting it reach anything but the public internet.
     * Redirects are followed by hand so each hop is checked the same way as the
     * URL the user gave us, and the body is read against a cap so a hostile
     * feed cannot stream until memory runs out.
     */
    private function fetch(string $feedUrl): string
    {
        $url = $feedUrl;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->guard->assertFetchable($url);

            $response = Http::timeout(15)
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->withHeaders(['User-Agent' => 'Chronos Calendar'])
                ->get($url);

            if (! $response->redirect()) {
                $response->throw();

                return $this->readCapped($response);
            }

            $url = $this->nextHop($url, $response->header('Location'));
        }

        throw new RuntimeException('Feed redirected more than '.self::MAX_REDIRECTS.' times.');
    }

    /**
     * Absolute URLs and absolute paths only. A relative path would have to be
     * resolved against the current one, and no calendar provider sends those.
     */
    private function nextHop(string $from, string $location): string
    {
        if ($location === '') {
            throw new RuntimeException('Feed redirected without saying where to.');
        }

        if (is_string(parse_url($location, PHP_URL_SCHEME))) {
            return $location;
        }

        if (! str_starts_with($location, '/')) {
            throw new RuntimeException('Feed redirected to a relative location, which Chronos does not follow.');
        }

        $scheme = parse_url($from, PHP_URL_SCHEME);
        $host = parse_url($from, PHP_URL_HOST);
        $port = parse_url($from, PHP_URL_PORT);

        if (! is_string($scheme) || ! is_string($host)) {
            throw new RuntimeException('Feed redirected from a URL Chronos cannot read.');
        }

        return $scheme.'://'.$host.(is_int($port) ? ':'.$port : '').$location;
    }

    private function readCapped(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();

        // An in-memory body may have been read already; a network stream is not
        // seekable and is always at the start.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(8192);

            if (strlen($body) > self::MAX_BYTES) {
                throw new RuntimeException('Feed is larger than the '.self::MAX_BYTES.' byte limit.');
            }
        }

        return $body;
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
