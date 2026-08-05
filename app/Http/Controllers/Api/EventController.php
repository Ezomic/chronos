<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateEventAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Concerns\ResolvesEventTimes;
use App\Concerns\ResolvesTokenApp;
use App\DataObjects\EventSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventRequest;
use App\Http\Requests\Api\UpdateEventRequest;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    use InteractsWithCurrentUser;
    use ResolvesEventTimes;
    use ResolvesTokenApp;

    /** Most listings are a lookup by source id; this only bounds a bare list. */
    private const MAX_RESULTS = 200;

    public function store(StoreEventRequest $request, CreateEventAction $action): JsonResponse
    {
        $user = $this->currentUser();
        $source = $this->sourceFrom($request);
        $app = $this->tokenApp();

        // An app-scoped token may only speak for its own app. Tokens minted
        // before scoping have no app and keep their existing freedom.
        abort_if(
            $source !== null && $app !== null && $source->app !== $app,
            403,
            "This token may only create events for {$app}.",
        );

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

    /**
     * The calling app's own events, optionally narrowed to one source row.
     */
    public function index(Request $request): JsonResponse
    {
        $app = $this->requireTokenApp();

        $events = Event::query()
            ->whereIn('calendar_id', $this->currentUser()->calendars()->select('id'))
            ->where('source_app', $app)
            ->when(
                $request->filled('source.type'),
                fn ($query) => $query->where('source_type', $request->string('source.type')->toString()),
            )
            ->when(
                $request->filled('source.id'),
                fn ($query) => $query->where('source_id', $request->string('source.id')->toString()),
            )
            ->orderBy('starts_at')
            ->limit(self::MAX_RESULTS)
            ->get();

        return response()->json([
            'data' => $events->map(fn (Event $event) => $this->payload($event))->all(),
            // A bare list is capped; narrow with a source filter to be sure of
            // seeing everything.
            'truncated' => $events->count() === self::MAX_RESULTS,
        ]);
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $this->authorizeManaging($event);

        $attributes = [];

        foreach (['title', 'description', 'location'] as $field) {
            if ($request->has($field)) {
                $attributes[$field] = $request->input($field);
            }
        }

        if ($request->has('starts_at')) {
            $allDay = $request->has('all_day') ? $request->boolean('all_day') : $event->all_day;

            [$startsAt, $endsAt, $timezone] = $this->resolveEventTimes(
                $allDay,
                $request->string('timezone')->toString() ?: $event->timezone,
                $request->string('starts_at')->toString(),
                $request->string('ends_at')->toString(),
            );

            $attributes['starts_at'] = $startsAt;
            $attributes['ends_at'] = $endsAt;
            $attributes['all_day'] = $allDay;
            $attributes['timezone'] = $timezone;

            // A moved event has to remind again rather than stay silent on a
            // stamp from where it used to be.
            $attributes['reminder_sent_at'] = null;
            $attributes['reminder_sent_for'] = null;
        }

        if ($attributes !== []) {
            $event->forceFill($attributes)->save();
        }

        return $this->respond($event->refresh(), 200);
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->authorizeManaging($event);

        $event->delete();

        return response()->json([], 204);
    }

    /**
     * A managed event has to belong to the token's user, sit on a writable
     * calendar, and have been created by the app the token speaks for. Anything
     * else is a 404 rather than a 403, so a token cannot probe for events it
     * has no business knowing about.
     */
    private function authorizeManaging(Event $event): void
    {
        $app = $this->requireTokenApp();
        $calendar = $event->calendar;

        abort_unless(
            $calendar !== null
                && $calendar->user_id === $this->currentUser()->id
                && $calendar->is_writable
                && $event->source_app === $app,
            404,
        );
    }

    private function requireTokenApp(): string
    {
        $app = $this->tokenApp();

        abort_if(
            $app === null,
            403,
            'This token is not scoped to an app, so it cannot manage events. Reissue it with --app.',
        );

        return $app;
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
        return response()->json($this->payload($event), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'all_day' => $event->all_day,
            'timezone' => $event->timezone,
            'calendar_id' => $event->calendar_id,
            'source' => $event->source_app === null ? null : [
                'app' => $event->source_app,
                'type' => $event->source_type,
                'id' => $event->source_id,
                'url' => $event->source_url,
            ],
            'url' => route('calendar.index', [
                'view' => 'day',
                'date' => $event->starts_at->setTimezone($event->timezone)->toDateString(),
            ]),
        ];
    }
}
