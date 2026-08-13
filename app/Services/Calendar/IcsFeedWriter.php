<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\Calendar;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Renders a calendar as an iCalendar document a phone can subscribe to.
 *
 * The mirror image of IcsCalendarService, which reads them.
 */
class IcsFeedWriter
{
    /** Far enough back to be useful, not so far the feed is enormous. */
    private const PAST_MONTHS = 3;

    private const FUTURE_MONTHS = 12;

    public function write(Calendar $calendar): string
    {
        $document = new VCalendar([
            'PRODID' => '-//Thijssensoftware//Chronos//EN',
            'VERSION' => '2.0',
            'CALSCALE' => 'GREGORIAN',
            'X-WR-CALNAME' => $calendar->name,
            'X-WR-TIMEZONE' => $calendar->timezone,
        ]);

        foreach ($this->events($calendar) as $event) {
            $this->addEvent($document, $event);
        }

        return $document->serialize();
    }

    /**
     * @return Collection<int, Event>
     */
    private function events(Calendar $calendar): Collection
    {
        $from = CarbonImmutable::now()->subMonths(self::PAST_MONTHS);
        $to = CarbonImmutable::now()->addMonths(self::FUTURE_MONTHS);

        return $calendar->events()
            // Repeating events go out as their rule, so a series anchored long
            // ago still belongs in the feed.
            ->where(fn ($query) => $query
                ->whereNotNull('rrule')
                ->orWhere(fn ($inner) => $inner
                    ->where('starts_at', '<', $to)
                    ->where('ends_at', '>', $from)))
            ->with('overrides')
            ->orderBy('starts_at')
            ->get();
    }

    private function addEvent(VCalendar $document, Event $event): void
    {
        $vevent = $document->add('VEVENT', [
            'UID' => $this->uid($event),
            'SUMMARY' => $event->title,
            'DTSTAMP' => $event->updated_at?->utc()->toDateTime() ?? now()->utc()->toDateTime(),
        ]);

        // add() is declared as returning a Node; only a component can carry the
        // properties below.
        if (! $vevent instanceof VEvent) {
            return;
        }

        if ($event->all_day) {
            // Dates, not instants, and the end stays exclusive exactly as it is
            // stored.
            $vevent->add('DTSTART', $event->starts_at->utc()->toDateTime(), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $event->ends_at->utc()->toDateTime(), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $event->starts_at->utc()->toDateTime());
            $vevent->add('DTEND', $event->ends_at->utc()->toDateTime());
        }

        if ($event->description !== null && $event->description !== '') {
            $vevent->add('DESCRIPTION', $event->description);
        }

        if ($event->location !== null && $event->location !== '') {
            $vevent->add('LOCATION', $event->location);
        }

        if ($event->rrule === null) {
            return;
        }

        // As a rule rather than expanded instances, so the subscriber's own app
        // does the expanding and the feed stays small.
        $vevent->add('RRULE', $event->rrule);

        foreach ($this->exceptions($event) as $excluded) {
            $vevent->add('EXDATE', $excluded->toDateTime());
        }
    }

    /**
     * Occurrence starts the series no longer produces: ones the user removed,
     * and ones they edited into events of their own, which travel as their own
     * VEVENT.
     *
     * @return array<int, CarbonImmutable>
     */
    private function exceptions(Event $event): array
    {
        $exceptions = [];

        foreach ($event->excluded_dates ?? [] as $excluded) {
            $exceptions[] = CarbonImmutable::parse($excluded, 'UTC');
        }

        foreach ($event->overrides as $override) {
            if ($override->overrides_starts_at !== null) {
                $exceptions[] = CarbonImmutable::instance($override->overrides_starts_at)->utc();
            }
        }

        return $exceptions;
    }

    /**
     * Stable across regenerations, so a subscriber updates an event rather than
     * seeing a new one each time it polls.
     */
    private function uid(Event $event): string
    {
        $appUrl = config('app.url');
        $host = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;

        return 'chronos-'.$event->id.'@'.(is_string($host) ? $host : 'chronos');
    }
}
