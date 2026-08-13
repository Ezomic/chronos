<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds the events an event collides with.
 *
 * Advisory only. Double-booking is frequently deliberate, so nothing here
 * refuses a save; it just says what else is in the way.
 */
class ClashDetector
{
    /** How far ahead a repeating event is checked. */
    private const HORIZON_DAYS = 90;

    /** Enough to make the point without turning the warning into a list. */
    private const MAX_CLASHES = 3;

    public function __construct(private readonly RecurrenceExpander $expander) {}

    /**
     * Descriptions of what this event runs into, soonest first, empty when the
     * calendar is clear.
     *
     * @return array<int, string>
     */
    public function for(Event $event): array
    {
        $calendar = $event->calendar;

        if ($calendar === null) {
            return [];
        }

        $ranges = $this->rangesFor($event);

        if ($ranges === []) {
            return [];
        }

        $from = $ranges[0]['starts_at'];
        $to = $ranges[0]['ends_at'];

        foreach ($ranges as $range) {
            if ($range['ends_at']->greaterThan($to)) {
                $to = $range['ends_at'];
            }
        }

        /** @var array<int, array{starts_at: CarbonImmutable, label: string}> $clashes */
        $clashes = [];

        foreach ($this->candidates($event, $from, $to) as $other) {
            foreach ($this->rangesFor($other, $from, $to) as $otherRange) {
                foreach ($ranges as $range) {
                    if ($this->overlap($range, $otherRange)) {
                        $clashes[] = [
                            'starts_at' => $otherRange['starts_at'],
                            'label' => $this->describe($other, $otherRange['starts_at']),
                        ];

                        continue 3;
                    }
                }
            }
        }

        usort(
            $clashes,
            fn (array $a, array $b): int => $a['starts_at']->getTimestamp() <=> $b['starts_at']->getTimestamp(),
        );

        $labels = [];

        foreach ($clashes as $clash) {
            if (! in_array($clash['label'], $labels, true)) {
                $labels[] = $clash['label'];
            }

            if (count($labels) === self::MAX_CLASHES) {
                break;
            }
        }

        return $labels;
    }

    /**
     * Every span this event actually occupies in the window, which for a
     * repeating event is one per occurrence rather than one per row.
     *
     * @return array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    private function rangesFor(Event $event, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        if ($event->rrule === null) {
            return [[
                'starts_at' => CarbonImmutable::instance($event->starts_at),
                'ends_at' => CarbonImmutable::instance($event->ends_at),
            ]];
        }

        $from ??= CarbonImmutable::instance($event->starts_at);
        $to ??= $from->addDays(self::HORIZON_DAYS);

        $length = (int) $event->starts_at->diffInSeconds($event->ends_at);

        // An occurrence that starts before the window can still run into it, and
        // the expander only yields occurrences that start inside. Reach back far
        // enough to catch one, then let the overlap test do the deciding.
        $from = $from->subSeconds(max($length, 86400));

        return array_map(
            fn (array $occurrence): array => [
                'starts_at' => CarbonImmutable::instance($occurrence['starts_at']),
                'ends_at' => CarbonImmutable::instance($occurrence['starts_at'])->addSeconds((int) $length),
            ],
            $this->expander->expand($event, $from, $to),
        );
    }

    /**
     * Other events that could be in the way. Hidden calendars are left out, on
     * the grounds that the user is not looking at them; mirrored ones are not,
     * because a meeting someone else booked is still a real commitment.
     *
     * @return Collection<int, Event>
     */
    private function candidates(Event $event, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $owned = fn (Builder $query) => $query
            ->where('user_id', $event->calendar?->user_id)
            ->where('is_visible', true);

        $exclude = fn (Builder $query) => $query
            ->whereKeyNot($event->id)
            // An override replaces one of its series' occurrences rather than
            // colliding with it, and the same the other way round.
            ->where(fn (Builder $inner) => $inner
                ->whereNull('overrides_event_id')
                ->orWhere('overrides_event_id', '!=', $event->id))
            ->when(
                $event->overrides_event_id !== null,
                fn (Builder $inner) => $inner->whereKeyNot($event->overrides_event_id),
            );

        $single = Event::query()
            ->whereHas('calendar', $owned)
            ->where($exclude)
            ->whereNull('rrule')
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with('calendar')
            ->get();

        // A series can be anchored long before the window and still produce an
        // occurrence inside it, so it cannot be filtered on its own end.
        $recurring = Event::query()
            ->whereHas('calendar', $owned)
            ->where($exclude)
            ->whereNotNull('rrule')
            ->where('starts_at', '<', $to)
            ->with(['calendar', 'overrides'])
            ->get();

        return $single->concat($recurring);
    }

    /**
     * @param  array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}  $a
     * @param  array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}  $b
     */
    private function overlap(array $a, array $b): bool
    {
        // Strict, so back-to-back events do not count. Ending as another starts
        // is the normal shape of a day, not a problem.
        return $a['starts_at']->lessThan($b['ends_at'])
            && $a['ends_at']->greaterThan($b['starts_at']);
    }

    private function describe(Event $event, CarbonImmutable $startsAt): string
    {
        $local = $startsAt->setTimezone($event->timezone);

        return $event->all_day
            ? $event->title.' (all day, '.$local->format('D j M').')'
            : $event->title.' ('.$local->format('D j M, H:i').')';
    }
}
