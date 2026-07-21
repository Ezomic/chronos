<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly RecurrenceExpander $expander) {}

    public function index(): Response
    {
        $user = $this->currentUser();
        $now = CarbonImmutable::now();

        // A flat, sorted list of occurrences; the page buckets them into
        // today / upcoming / next in the viewer's local time (events are stored
        // in UTC and rendered per their own timezone, so the day boundary can't
        // be decided reliably on the server).
        $events = array_map(
            fn (array $occurrence): array => $this->serialize($occurrence[0], $occurrence[1]),
            $this->occurrences($user, $now->subDay(), $now->addDays(14)),
        );

        return Inertia::render('Dashboard', [
            'events' => $events,
            'calendars' => $user->calendars()->where('is_writable', true)->count(),
            'needs_reconnect' => $user->connectedAccounts()
                ->where('sync_status', 'error')
                ->exists(),
        ]);
    }

    /**
     * All event occurrences (single + expanded recurring) in the window,
     * sorted by start.
     *
     * @return array<int, array{0: Event, 1: CarbonImmutable}>
     */
    private function occurrences(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ownedVisible = fn ($query) => $query
            ->where('user_id', $user->id)
            ->where('is_visible', true);

        $single = Event::query()
            ->whereHas('calendar', $ownedVisible)
            ->whereNull('rrule')
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with('calendar:id,color')
            ->get();

        $recurring = Event::query()
            ->whereHas('calendar', $ownedVisible)
            ->whereNotNull('rrule')
            ->where('starts_at', '<', $to)
            ->with('calendar:id,color')
            ->get();

        $occurrences = [];

        foreach ($single as $event) {
            $occurrences[] = [$event, CarbonImmutable::instance($event->starts_at)];
        }

        foreach ($recurring as $master) {
            foreach ($this->expander->expand($master, $from, $to) as $occurrence) {
                $occurrences[] = [$master, CarbonImmutable::instance($occurrence['starts_at'])];
            }
        }

        usort($occurrences, fn ($a, $b) => $a[1]->getTimestamp() <=> $b[1]->getTimestamp());

        return $occurrences;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Event $event, CarbonInterface $start): array
    {
        return [
            'key' => $event->id.'|'.$start->toIso8601String(),
            'title' => $event->title,
            'color' => $event->calendar->color ?? Calendar::COLOR_PALETTE[0],
            'all_day' => $event->all_day,
            'starts_at' => $start->toIso8601String(),
            'timezone' => $event->timezone,
            'location' => $event->location,
        ];
    }
}
