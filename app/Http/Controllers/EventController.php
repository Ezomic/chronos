<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateEventAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Concerns\ResolvesEventTimes;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Calendar;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class EventController extends Controller
{
    use InteractsWithCurrentUser;
    use ResolvesEventTimes;

    private const FREQUENCIES = [
        'daily' => 'DAILY',
        'weekly' => 'WEEKLY',
        'monthly' => 'MONTHLY',
        'yearly' => 'YEARLY',
    ];

    public function store(StoreEventRequest $request, CreateEventAction $action): RedirectResponse
    {
        $calendar = Calendar::findOrFail($request->integer('calendar_id'));

        [$startsAt, $endsAt, $timezone] = $this->resolveEventTimes(
            $request->boolean('all_day'),
            $request->input('timezone'),
            $request->string('starts_at')->toString(),
            $request->string('ends_at')->toString(),
        );

        $action->handle(
            calendar: $calendar,
            title: $request->string('title')->toString(),
            startsAt: $startsAt,
            endsAt: $endsAt,
            allDay: $request->boolean('all_day'),
            timezone: $timezone,
            description: $request->input('description'),
            location: $request->input('location'),
            rrule: $this->buildRrule($request, $timezone),
        );

        return back();
    }

    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        abort_unless($this->currentUser()->can('update', $event), 403);

        $calendar = Calendar::findOrFail($request->integer('calendar_id'));

        [$startsAt, $endsAt, $timezone] = $this->resolveEventTimes(
            $request->boolean('all_day'),
            $request->input('timezone'),
            $request->string('starts_at')->toString(),
            $request->string('ends_at')->toString(),
        );

        $event->forceFill([
            'calendar_id' => $calendar->id,
            'title' => $request->string('title')->toString(),
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $request->boolean('all_day'),
            'timezone' => $timezone,
            'rrule' => $this->buildRrule($request, $timezone),
        ])->save();

        return back();
    }

    public function destroy(Event $event): RedirectResponse
    {
        abort_unless($this->currentUser()->can('delete', $event), 403);

        $event->delete();

        return back();
    }

    /**
     * Build an RRULE string from the request's recurrence fields, or null when
     * the event doesn't repeat. UNTIL is stored as an inclusive end-of-day UTC
     * timestamp.
     */
    private function buildRrule(FormRequest $request, string $timezone): ?string
    {
        $frequency = $request->string('frequency')->toString();

        if (! array_key_exists($frequency, self::FREQUENCIES)) {
            return null;
        }

        $rrule = 'FREQ='.self::FREQUENCIES[$frequency];

        if ($request->filled('until')) {
            $until = CarbonImmutable::parse($request->string('until')->toString(), $timezone)
                ->endOfDay()
                ->utc()
                ->format('Ymd\THis\Z');

            $rrule .= ';UNTIL='.$until;
        }

        return $rrule;
    }
}
