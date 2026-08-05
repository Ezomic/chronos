<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateEventAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Concerns\ResolvesEventTimes;
use App\DataObjects\EventSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventRequest;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    use InteractsWithCurrentUser;
    use ResolvesEventTimes;

    public function store(StoreEventRequest $request, CreateEventAction $action): JsonResponse
    {
        $user = $this->currentUser();
        $source = $this->sourceFrom($request);

        // Consuming apps retry, and a user can press "Create event" twice on the
        // same message. Both should land on the one event, so a source we have
        // already seen returns it instead of creating a second.
        if ($source !== null) {
            $existing = $this->existingFor($user, $source);

            if ($existing !== null) {
                return $this->respond($existing, 200);
            }
        }

        // The token is bound to a user, so events land in their default
        // writable calendar without a calendar parameter.
        $calendar = $user->calendars()
            ->where('is_writable', true)
            ->orderByDesc('is_default')
            ->first();

        abort_if($calendar === null, 422, 'No writable calendar is available.');

        [$startsAt, $endsAt, $timezone] = $this->resolveEventTimes(
            $request->boolean('all_day'),
            $request->string('timezone')->toString() ?: null,
            $request->string('starts_at')->toString(),
            $request->string('ends_at')->toString(),
        );

        $event = $action->handle(
            calendar: $calendar,
            title: $request->string('title')->toString(),
            startsAt: $startsAt,
            endsAt: $endsAt,
            allDay: $request->boolean('all_day'),
            timezone: $timezone,
            description: $request->string('description')->toString() ?: null,
            location: $request->string('location')->toString() ?: null,
            source: $source,
        );

        return $this->respond($event, 201);
    }

    private function sourceFrom(StoreEventRequest $request): ?EventSource
    {
        if (! $request->filled('source')) {
            return null;
        }

        return new EventSource(
            app: $request->string('source.app')->toString(),
            type: $request->string('source.type')->toString(),
            id: $request->string('source.id')->toString(),
            url: $request->string('source.url')->toString(),
        );
    }

    /**
     * The event already created for this source row, on any of the user's
     * calendars. Looking wider than the target calendar means a moved event is
     * still recognised instead of being created again.
     */
    private function existingFor(User $user, EventSource $source): ?Event
    {
        return Event::query()
            ->whereIn('calendar_id', $user->calendars()->select('id'))
            ->where('source_app', $source->app)
            ->where('source_type', $source->type)
            ->where('source_id', $source->id)
            ->first();
    }

    private function respond(Event $event, int $status): JsonResponse
    {
        return response()->json([
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'url' => route('calendar.index', [
                'view' => 'day',
                'date' => $event->starts_at->setTimezone($event->timezone)->toDateString(),
            ]),
        ], $status);
    }
}
