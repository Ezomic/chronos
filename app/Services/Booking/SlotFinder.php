<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\BookingType;
use App\Models\Event;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out which start times a booking type can be booked at right now.
 *
 * The owner's whole calendar is the source of truth: any event of theirs that
 * is not marked free blocks the time around it, wherever it came from. Only
 * start times leave this class, never anything about the events themselves.
 */
class SlotFinder
{
    public function __construct(private readonly RecurrenceExpander $expander) {}

    /**
     * @return Collection<int, CarbonImmutable>
     */
    public function slots(BookingType $type): Collection
    {
        $now = CarbonImmutable::now();
        $zone = $type->timezone;

        $firstDay = $now->setTimezone($zone)->startOfDay();
        $lastDay = $now->addDays($type->max_days_ahead)->setTimezone($zone)->startOfDay();

        return $this->open($type, $now, $this->candidates($type, $firstDay, $lastDay));
    }

    public function isBookable(BookingType $type, CarbonImmutable $startsAt): bool
    {
        $day = $startsAt->setTimezone($type->timezone)->startOfDay();

        return $this->open($type, CarbonImmutable::now(), $this->candidates($type, $day, $day))
            ->contains(fn (CarbonImmutable $slot) => $slot->getTimestamp() === $startsAt->getTimestamp());
    }

    /**
     * Every grid position inside the weekly hours of the days given, before
     * notice, horizon and conflicts are considered.
     *
     * @return Collection<int, CarbonImmutable>
     */
    private function candidates(BookingType $type, CarbonImmutable $firstDay, CarbonImmutable $lastDay): Collection
    {
        $windows = $type->windows()->get();
        $slots = [];

        for ($day = $firstDay; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()->startOfDay()) {
            foreach ($windows->where('weekday', $day->dayOfWeekIso) as $window) {
                $date = $day->format('Y-m-d');
                $closes = CarbonImmutable::parse($date.' '.$window->end_time, $type->timezone);
                $start = CarbonImmutable::parse($date.' '.$window->start_time, $type->timezone);

                while ($start->addMinutes($type->duration_minutes)->lessThanOrEqualTo($closes)) {
                    $slots[] = $start->utc();
                    $start = $start->addMinutes($type->slot_step_minutes);
                }
            }
        }

        return collect($slots);
    }

    /**
     * @param  Collection<int, CarbonImmutable>  $candidates
     * @return Collection<int, CarbonImmutable>
     */
    private function open(BookingType $type, CarbonImmutable $now, Collection $candidates): Collection
    {
        $earliest = $now->addMinutes($type->min_notice_minutes)->getTimestamp();
        $latest = $now->addDays($type->max_days_ahead)->getTimestamp();

        $inRange = $candidates
            ->filter(fn (CarbonImmutable $slot) => $slot->getTimestamp() >= $earliest && $slot->getTimestamp() <= $latest)
            ->values();

        if ($inRange->isEmpty()) {
            return $inRange;
        }

        $buffer = $type->buffer_minutes * 60;
        $length = $type->duration_minutes * 60;
        $busy = $this->busy(
            $type,
            CarbonImmutable::createFromTimestampUTC($inRange->first()->getTimestamp() - $buffer),
            CarbonImmutable::createFromTimestampUTC($inRange->last()->getTimestamp() + $length + $buffer),
        );

        return $inRange
            ->reject(function (CarbonImmutable $slot) use ($busy, $buffer, $length): bool {
                $from = $slot->getTimestamp();
                $to = $from + $length;

                foreach ($busy as [$busyFrom, $busyTo]) {
                    if ($from < $busyTo + $buffer && $to + $buffer > $busyFrom) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * Unix start and end of everything that keeps the owner busy in the window.
     *
     * @return array<int, array{int, int}>
     */
    private function busy(BookingType $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // An all-day event is stored as a floating UTC date and blocks the
        // owner's local day, which can start a few hours either side of it.
        $wideFrom = $from->subDay();
        $wideTo = $to->addDay();

        $events = Event::query()
            ->whereHas('calendar', fn ($query) => $query->where('user_id', $type->user_id))
            ->where('is_free', false)
            ->where('starts_at', '<', $wideTo)
            ->where(fn ($query) => $query
                ->whereNotNull('rrule')
                ->orWhere('ends_at', '>', $wideFrom))
            ->with('overrides')
            ->get();

        $busy = [];

        foreach ($events as $event) {
            foreach ($this->occurrences($event, $wideFrom, $wideTo) as [$starts, $ends]) {
                $busy[] = $event->all_day
                    ? $this->wholeDays($type, $starts, $ends)
                    : [$starts->getTimestamp(), $ends->getTimestamp()];
            }
        }

        return $busy;
    }

    /**
     * @return array<int, array{CarbonImmutable, CarbonImmutable}>
     */
    private function occurrences(Event $event, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($event->rrule === null) {
            return [[CarbonImmutable::instance($event->starts_at), CarbonImmutable::instance($event->ends_at)]];
        }

        // An occurrence starting before the window can still run into it, and
        // the expander only yields those that start inside.
        $reach = max((int) $event->starts_at->diffInSeconds($event->ends_at), 86400);

        return array_map(
            fn (array $occurrence): array => [
                CarbonImmutable::instance($occurrence['starts_at']),
                CarbonImmutable::instance($occurrence['ends_at']),
            ],
            $this->expander->expand($event, $from->subSeconds($reach), $to),
        );
    }

    /**
     * @return array{int, int}
     */
    private function wholeDays(BookingType $type, CarbonImmutable $starts, CarbonImmutable $ends): array
    {
        return [
            CarbonImmutable::parse($starts->format('Y-m-d'), $type->timezone)->getTimestamp(),
            CarbonImmutable::parse($ends->format('Y-m-d'), $type->timezone)->getTimestamp(),
        ];
    }
}
