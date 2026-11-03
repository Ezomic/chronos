<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Event;
use App\Models\User;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ListAgendaAction
{
    public function __construct(private readonly RecurrenceExpander $expander) {}

    /**
     * The user's occurrences that overlap [from, to), sorted by start. Hidden
     * calendars stay out, as they do on the web calendar.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ownedVisible = fn (Builder $query) => $query
            ->where('user_id', $user->id)
            ->where('is_visible', true);

        // All-day events are floating dates stored as midnight UTC, so they can
        // sit up to a day away from the user's zone. Pad the query by a day and
        // decide overlap precisely below.
        $queryFrom = $from->subDay();
        $queryTo = $to->addDay();

        $single = Event::query()
            ->whereHas('calendar', $ownedVisible)
            ->whereNull('rrule')
            ->where('starts_at', '<', $queryTo)
            ->where('ends_at', '>', $queryFrom)
            ->with('calendar:id,name,color')
            ->get();

        $recurring = Event::query()
            ->whereHas('calendar', $ownedVisible)
            ->whereNotNull('rrule')
            ->where('starts_at', '<', $queryTo)
            ->with(['calendar:id,name,color', 'overrides'])
            ->get();

        $items = [];

        foreach ($single as $event) {
            $this->push($items, $event, $event->starts_at, $event->ends_at, $user, $from, $to);
        }

        foreach ($recurring as $master) {
            // The expander matches on an occurrence's start, so widen the window
            // by the series' duration to catch one that began before `from`.
            $duration = (int) $master->starts_at->diffInSeconds($master->ends_at, true);

            foreach ($this->expander->expand($master, $queryFrom->subSeconds($duration), $queryTo) as $occurrence) {
                $this->push($items, $master, $occurrence['starts_at'], $occurrence['ends_at'], $user, $from, $to);
            }
        }

        usort($items, fn (array $a, array $b): int => [$a['start'], $a['end'], $a['event_id']]
            <=> [$b['start'], $b['end'], $b['event_id']]);

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function push(
        array &$items,
        Event $event,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        User $user,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        if (! $this->overlaps($event, $startsAt, $endsAt, $user, $from, $to)) {
            return;
        }

        $start = self::iso($startsAt);

        $items[] = [
            'id' => "{$event->id}@{$start}",
            'event_id' => $event->id,
            'title' => $event->title,
            'start' => $start,
            'end' => self::iso($endsAt),
            'timezone' => $event->timezone,
            'all_day' => $event->all_day,
            'is_free' => $event->is_free,
            'calendar' => [
                'name' => $event->calendar->name ?? '',
                'color' => $event->calendar->color ?? '',
            ],
            'url' => route('calendar.index', [
                'view' => 'day',
                'date' => $startsAt->setTimezone($event->timezone)->toDateString(),
            ]),
        ];
    }

    private function overlaps(
        Event $event,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        User $user,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): bool {
        if ($event->all_day) {
            $startsAt = $this->floating($startsAt, $user);
            $endsAt = $this->floating($endsAt, $user);
        }

        return $startsAt < $to && $endsAt > $from;
    }

    /**
     * An all-day date as midnight in the user's zone rather than in UTC.
     */
    private function floating(CarbonInterface $at, User $user): CarbonImmutable
    {
        return CarbonImmutable::parse($at->utc()->toDateString(), $user->timezone)->startOfDay();
    }

    private static function iso(CarbonInterface $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
