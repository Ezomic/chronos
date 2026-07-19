<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateEventAction;
use App\Concerns\ResolvesEventTimes;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Calendar;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;

class EventController extends Controller
{
    use ResolvesEventTimes;

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
        );

        return back();
    }

    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        abort_unless($request->user()->can('update', $event), 403);

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
        ])->save();

        return back();
    }

    public function destroy(Event $event): RedirectResponse
    {
        abort_unless(request()->user()->can('delete', $event), 403);

        $event->delete();

        return back();
    }
}
