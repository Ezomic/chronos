<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use DateInterval;
use RuntimeException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime;
use Sabre\VObject\Reader;

/**
 * Reads an uploaded iCalendar file into event attributes.
 *
 * Unlike IcsCalendarService, which mirrors a subscribed feed, this does not
 * expand recurrence: an imported repeating event should arrive as a repeating
 * event the user can edit, not as a pile of instances.
 */
class IcsFileReader
{
    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException when the file is not an iCalendar document
     */
    public function read(string $document): array
    {
        try {
            $calendar = Reader::read($document, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            throw new RuntimeException('That file is not a calendar we can read.', 0, $e);
        }

        if (! $calendar instanceof VCalendar) {
            throw new RuntimeException('That file is not a calendar we can read.');
        }

        $events = [];

        foreach ($calendar->select('VEVENT') as $vevent) {
            if (! $vevent instanceof VEvent) {
                continue;
            }

            $dtstart = $this->firstProperty($vevent, 'DTSTART');

            if (! $dtstart instanceof DateTime) {
                continue;
            }

            $events[] = $this->normalize($vevent, $dtstart);
        }

        if ($events === []) {
            throw new RuntimeException('That file has no events in it.');
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(VEvent $event, DateTime $dtstart): array
    {
        $allDay = ! $dtstart->hasTime();

        [$startsAt, $timezone] = $this->resolveStart($dtstart, $allDay);
        $endsAt = $this->resolveEnd($event, $startsAt, $allDay);

        $title = $this->stringValue($this->firstProperty($event, 'SUMMARY'));

        return [
            'uid' => $this->stringValue($this->firstProperty($event, 'UID')),
            'title' => $title ?? '(no title)',
            'description' => $this->stringValue($this->firstProperty($event, 'DESCRIPTION')),
            'location' => $this->stringValue($this->firstProperty($event, 'LOCATION')),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'timezone' => $timezone,
            // Kept as written, so the event repeats after import rather than
            // arriving flattened.
            'rrule' => $this->stringValue($this->firstProperty($event, 'RRULE')),
        ];
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
