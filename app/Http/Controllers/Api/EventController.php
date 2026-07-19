<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateEventAction;
use App\Concerns\ResolvesEventTimes;
use App\DataObjects\EventSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventRequest;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    use ResolvesEventTimes;

    public function store(StoreEventRequest $request, CreateEventAction $action): JsonResponse
    {
        // The token is bound to a user, so events land in their default
        // writable calendar without a calendar parameter.
        $calendar = $request->user()->calendars()
            ->where('is_writable', true)
            ->orderByDesc('is_default')
            ->first();

        abort_if($calendar === null, 422, 'No writable calendar is available.');

        [$startsAt, $endsAt, $timezone] = $this->resolveEventTimes(
            $request->boolean('all_day'),
            $request->input('timezone'),
            $request->string('starts_at')->toString(),
            $request->string('ends_at')->toString(),
        );

        $source = $request->filled('source')
            ? new EventSource(
                app: $request->string('source.app')->toString(),
                type: $request->string('source.type')->toString(),
                id: $request->string('source.id')->toString(),
                url: $request->string('source.url')->toString(),
            )
            : null;

        $event = $action->handle(
            calendar: $calendar,
            title: $request->string('title')->toString(),
            startsAt: $startsAt,
            endsAt: $endsAt,
            allDay: $request->boolean('all_day'),
            timezone: $timezone,
            description: $request->input('description'),
            location: $request->input('location'),
            source: $source,
        );

        return response()->json([
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'url' => route('calendar.index', [
                'view' => 'day',
                'date' => $event->starts_at->setTimezone($timezone)->toDateString(),
            ]),
        ], 201);
    }
}
