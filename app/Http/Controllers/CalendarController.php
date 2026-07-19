<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
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

        $events = Event::query()
            ->whereHas('calendar', function ($query) use ($request): void {
                $query->where('user_id', $request->user()->id)
                    ->where('is_visible', true);
            })
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with('calendar:id,color')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'calendar_id' => $event->calendar_id,
                'title' => $event->title,
                'description' => $event->description,
                'color' => $event->calendar->color,
                'all_day' => $event->all_day,
                'starts_at' => $event->starts_at->toIso8601String(),
                'ends_at' => $event->ends_at->toIso8601String(),
                'timezone' => $event->timezone,
                'location' => $event->location,
                'source_app' => $event->source_app,
                'source_url' => $event->source_url,
            ])
            ->values();

        $calendars = $request->user()->calendars()
            ->where('is_writable', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'is_default'])
            ->values();

        return Inertia::render('calendar/Index', [
            'view' => $view,
            'date' => $anchor->toDateString(),
            'events' => $events,
            'calendars' => $calendars,
        ]);
    }

    private function parseAnchor(string $date): CarbonImmutable
    {
        if ($date === '') {
            return CarbonImmutable::today();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }
}
