<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
use App\Models\Calendar;
use App\Models\Event;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    use InteractsWithCurrentUser;

    public function __construct(private readonly RecurrenceExpander $expander) {}

    /**
     * Render the calendar. The view and anchor date live in the URL
     * (?view=month&date=YYYY-MM-DD) so navigation is a normal Inertia visit
     * and there's no client-side event store to fall out of sync. Only events
     * overlapping the visible window are returned.
     */
    private const VIEWS = ['month', 'week', 'day'];

    public function index(Request $request): Response
    {
        $view = $request->string('view')->toString();
        $view = in_array($view, self::VIEWS, true) ? $view : 'month';

        $anchor = $this->parseAnchor($request->string('date')->toString());

        [$gridStart, $gridEnd] = match ($view) {
            'week' => [$anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->startOfWeek(CarbonImmutable::MONDAY)->addDays(7)],
            'day' => [$anchor, $anchor->addDay()],
            default => [
                $anchor->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY),
                $anchor->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY)->addDays(42),
            ],
        };

        // Pad a day each side so an event near a boundary is never dropped by a
        // timezone offset between storage (UTC) and the viewer's zone.
        $from = $gridStart->subDay();
        $to = $gridEnd->addDay();

        $ownedVisible = fn ($query) => $query
            ->where('user_id', $this->currentUser()->id)
            ->where('is_visible', true);

        // Non-recurring events overlapping the window.
        $single = Event::query()
            ->whereHas('calendar', $ownedVisible)
            ->whereNull('rrule')
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with('calendar:id,name,color,is_writable')
            ->get();

        // Recurring masters whose series could produce an occurrence in the
        // window (anchored before the window ends); expanded below.
        $recurring = Event::query()
            ->whereHas('calendar', $ownedVisible)
            ->whereNotNull('rrule')
            ->where('starts_at', '<', $to)
            ->with('calendar:id,name,color,is_writable')
            ->get();

        $events = collect();

        foreach ($single as $event) {
            $events->push($this->serializeOccurrence($event, $event->starts_at, $event->ends_at));
        }

        foreach ($recurring as $master) {
            foreach ($this->expander->expand($master, $from, $to) as $occurrence) {
                $events->push($this->serializeOccurrence($master, $occurrence['starts_at'], $occurrence['ends_at']));
            }
        }

        $events = $events->sortBy('starts_at')->values();

        $calendars = $this->currentUser()->calendars()
            ->where('is_writable', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'is_default'])
            ->values();

        $templates = $this->currentUser()->eventTemplates()
            ->orderBy('name')
            ->get([
                'id', 'name', 'calendar_id', 'title', 'description', 'location',
                'all_day', 'duration_minutes', 'default_start_time', 'frequency',
                'reminder_minutes',
            ])
            ->values();

        return Inertia::render('calendar/Index', [
            'view' => $view,
            'date' => $anchor->toDateString(),
            'events' => $events,
            'calendars' => $calendars,
            'templates' => $templates,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOccurrence(Event $event, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        return [
            // Unique per occurrence so repeated instances don't collide as keys.
            'key' => $event->id.'|'.$startsAt->toIso8601String(),
            'id' => $event->id,
            'calendar_id' => $event->calendar_id,
            'calendar_name' => $event->calendar->name ?? '',
            // Mirrored (non-writable) calendars are read-only; the sheet shows
            // their events as read-only instead of an editable form.
            'editable' => (bool) ($event->calendar->is_writable ?? false),
            'title' => $event->title,
            'description' => $event->description,
            // whereHas('calendar') already guarantees the relation, so this only
            // guards the type: the frontend types color as non-nullable.
            'color' => $event->calendar->color ?? Calendar::COLOR_PALETTE[0],
            'all_day' => $event->all_day,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'timezone' => $event->timezone,
            'location' => $event->location,
            'source_app' => $event->source_app,
            'source_url' => $event->source_url,
            'rrule' => $event->rrule,
            'reminder_minutes' => $event->reminder_minutes,
            // The series anchor (for editing the whole series), null when single.
            'series_starts_at' => $event->rrule ? $event->starts_at->toIso8601String() : null,
            'series_ends_at' => $event->rrule ? $event->ends_at->toIso8601String() : null,
        ];
    }

    private function parseAnchor(string $date): CarbonImmutable
    {
        if ($date === '') {
            return CarbonImmutable::today();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $date)?->startOfDay()
                ?? CarbonImmutable::today();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }
}
