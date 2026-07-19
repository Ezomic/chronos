<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateEventAction;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Calendar;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

class EventController extends Controller
{
    public function store(StoreEventRequest $request, CreateEventAction $action): RedirectResponse
    {
        $calendar = Calendar::findOrFail($request->integer('calendar_id'));

        [$startsAt, $endsAt, $timezone] = $this->resolveTimes($request);

        $action->handle(
            calendar: $calendar,
            title: $request->string('title')->toString(),
            startsAt: $startsAt,
            endsAt: $endsAt,
            allDay: $request->boolean('all_day'),
            timezone: $timezone,
            description: $request->input('description'),
            location: $request->input('location'),
        );

        return back();
    }

    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        abort_unless($request->user()->can('update', $event), 403);

        $calendar = Calendar::findOrFail($request->integer('calendar_id'));

        [$startsAt, $endsAt, $timezone] = $this->resolveTimes($request);

        $event->forceFill([
            'calendar_id' => $calendar->id,
            'title' => $request->string('title')->toString(),
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $request->boolean('all_day'),
            'timezone' => $timezone,
        ])->save();

        return back();
    }

    public function destroy(Event $event): RedirectResponse
    {
        abort_unless(request()->user()->can('delete', $event), 403);

        $event->delete();

        return back();
    }

    /**
     * Resolve the request's local date/time inputs to UTC storage values.
     * All-day events become an exclusive midnight-UTC span with a floating
     * 'UTC' zone; timed events are parsed in their zone and converted to UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function resolveTimes(FormRequest $request): array
    {
        if ($request->boolean('all_day')) {
            $startDate = CarbonImmutable::parse($request->string('starts_at')->toString())->format('Y-m-d');
            $endDate = CarbonImmutable::parse($request->string('ends_at')->toString())->format('Y-m-d');

            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$startDate} 00:00", 'UTC');
            $endsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$endDate} 00:00", 'UTC')->addDay();

            return [$startsAt, $endsAt, 'UTC'];
        }

        $timezone = $request->string('timezone')->toString() ?: config('app.timezone');

        $startsAt = CarbonImmutable::parse($request->string('starts_at')->toString(), $timezone)->utc();
        $endsAt = CarbonImmutable::parse($request->string('ends_at')->toString(), $timezone)->utc();

        return [$startsAt, $endsAt, $timezone];
    }
}
