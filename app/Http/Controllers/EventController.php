<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateEventAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Concerns\ResolvesEventTimes;
use App\Concerns\ValidatesRecurrence;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Calendar;
use App\Models\Event;
use App\Services\Calendar\ClashDetector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EventController extends Controller
{
    use InteractsWithCurrentUser;
    use ResolvesEventTimes;
    use ValidatesRecurrence;

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
            rrule: $this->buildRrule($request, $timezone, $startsAt),
            reminderMinutes: $this->reminderMinutes($request),
        );

        $this->warnAboutClashes($event);

        return back();
    }

    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        abort_unless($this->currentUser()->can('update', $event), 403);

        $calendar = Calendar::findOrFail($request->integer('calendar_id'));

        if ($this->targetsOneOccurrence($request, $event)) {
            $this->warnAboutClashes($this->writeOverride($request, $event, $calendar));

            return back();
        }

        [$startsAt, $endsAt, $timezone] = $this->resolveEventTimes(
            $request->boolean('all_day'),
            $request->string('timezone')->toString() ?: null,
            $request->string('starts_at')->toString(),
            $request->string('ends_at')->toString(),
        );

        $reminderMinutes = $this->reminderMinutes($request);

        // Re-arm a spent reminder when its timing changes, so an edited event
        // reminds again instead of staying silent from a stale sent stamp.
        $reminderChanged = $reminderMinutes !== $event->reminder_minutes
            || ! $startsAt->equalTo($event->starts_at);

        $event->forceFill([
            'calendar_id' => $calendar->id,
            'title' => $request->string('title')->toString(),
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $request->boolean('all_day'),
            'timezone' => $timezone,
            'rrule' => $this->buildRrule($request, $timezone, $startsAt),
            'reminder_minutes' => $reminderMinutes,
            'reminder_sent_at' => $reminderChanged ? null : $event->reminder_sent_at,
        ])->save();

        $this->warnAboutClashes($event);

        return back();
    }

    public function destroy(Request $request, Event $event): RedirectResponse
    {
        abort_unless($this->currentUser()->can('delete', $event), 403);

        // Deleting an override on its own would let its series generate the
        // occurrence again, so the original start is excluded as well.
        if ($event->overrides_event_id !== null) {
            $this->excludeOccurrence($event->overriddenSeries, $event->overrides_starts_at);
            $event->delete();

            return $this->deleted($event);
        }

        if ($this->targetsOneOccurrence($request, $event)) {
            $start = $this->occurrenceStart($request);

            // Any edit the user had made to this occurrence goes with it.
            $event->overrides()->where('overrides_starts_at', $start)->forceDelete();
            $this->excludeOccurrence($event, $start);

            return back();
        }

        // A soft delete does not fire the foreign key cascade, so the series'
        // overrides have to come along by hand.
        $event->overrides()->delete();
        $event->delete();

        return $this->deleted($event);
    }

    public function restore(Event $event): RedirectResponse
    {
        abort_unless($this->currentUser()->can('restore', $event), 403);

        $event->restore();
        $this->restoreCascadedOverrides($event);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event restored.')]);

        return back();
    }

    /**
     * Bring back the overrides that went down with the series, and leave the
     * ones the user had already deleted on their own.
     *
     * Told apart by the exclusion list rather than by when they were deleted:
     * deleting an override always excludes its occurrence, and two deletes a
     * moment apart share a timestamp.
     */
    private function restoreCascadedOverrides(Event $series): void
    {
        $excluded = $series->excluded_dates ?? [];

        $series->overrides()->onlyTrashed()->get()
            ->reject(fn (Event $override): bool => in_array(
                $override->overrides_starts_at?->utc()->format('Y-m-d H:i:s'),
                $excluded,
                true,
            ))
            ->each(fn (Event $override) => $override->restore());
    }

    /**
     * Say what a saved event collides with, if anything. A warning and never a
     * refusal: double-booking is often deliberate, and blocking the save would
     * be worse than saying nothing.
     */
    private function warnAboutClashes(Event $event): void
    {
        $clashes = app(ClashDetector::class)->for($event->fresh() ?? $event);

        if ($clashes === []) {
            return;
        }

        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => __('Saved, but it overlaps :events.', [
                'events' => implode(', ', $clashes),
            ]),
        ]);
    }

    /**
     * Deleting is undoable, so say so and hand the page what it needs to offer
     * the way back.
     */
    private function deleted(Event $event): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Event deleted.'),
            'action' => [
                'label' => __('Undo'),
                'url' => route('events.restore', $event),
            ],
        ]);

        return back();
    }

    /**
     * Whether this request means one occurrence rather than the whole series.
     * Only a series has occurrences to single out.
     */
    private function targetsOneOccurrence(Request $request, Event $event): bool
    {
        return $request->string('scope')->toString() === 'occurrence'
            && $event->rrule !== null
            && $request->filled('occurrence_starts_at');
    }

    private function occurrenceStart(Request $request): CarbonImmutable
    {
        return CarbonImmutable::parse($request->string('occurrence_starts_at')->toString())->utc();
    }

    /**
     * Store this occurrence as an event of its own. The series keeps its rule
     * and stops generating that one, so the two never both appear.
     */
    private function writeOverride(UpdateEventRequest $request, Event $series, Calendar $calendar): Event
    {
        [$startsAt, $endsAt, $timezone] = $this->resolveEventTimes(
            $request->boolean('all_day'),
            $request->string('timezone')->toString() ?: null,
            $request->string('starts_at')->toString(),
            $request->string('ends_at')->toString(),
        );

        $occurrenceStart = $this->occurrenceStart($request);

        // withTrashed: a deleted override still holds the unique slot for its
        // occurrence, so reuse it rather than colliding with it.
        $override = Event::withTrashed()->firstOrNew([
            'overrides_event_id' => $series->id,
            'overrides_starts_at' => $occurrenceStart,
        ]);

        $override->forceFill([
            'deleted_at' => null,
            'calendar_id' => $calendar->id,
            'title' => $request->string('title')->toString(),
            'description' => $request->input('description'),
            'location' => $request->input('location'),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $request->boolean('all_day'),
            'timezone' => $timezone,
            // An occurrence does not carry a rule of its own.
            'rrule' => null,
            'overrides_event_id' => $series->id,
            'overrides_starts_at' => $occurrenceStart,
            'reminder_minutes' => $this->reminderMinutes($request),
            'reminder_sent_at' => null,
        ])->save();

        return $override;
    }

    private function excludeOccurrence(?Event $series, ?CarbonInterface $startsAt): void
    {
        if ($series === null || $startsAt === null) {
            return;
        }

        $excluded = $series->excluded_dates ?? [];
        $excluded[] = CarbonImmutable::instance($startsAt)->utc()->format('Y-m-d H:i:s');

        $series->forceFill(['excluded_dates' => array_values(array_unique($excluded))])->save();
    }

    private function reminderMinutes(FormRequest $request): ?int
    {
        return $request->filled('reminder_minutes')
            ? $request->integer('reminder_minutes')
            : null;
    }

    /**
     * Build an RRULE string from the request's recurrence fields, or null when
     * the event doesn't repeat. UNTIL is stored as an inclusive end-of-day UTC
     * timestamp.
     */
    private function buildRrule(FormRequest $request, string $timezone, CarbonImmutable $startsAt): ?string
    {
        $frequency = $request->string('frequency')->toString();

        if (! array_key_exists($frequency, self::FREQUENCIES)) {
            return null;
        }

        $parts = ['FREQ='.self::FREQUENCIES[$frequency]];

        $interval = $request->integer('interval');

        if ($interval > 1) {
            $parts[] = 'INTERVAL='.$interval;
        }

        $byDay = $this->byDay($request, $frequency, $timezone, $startsAt);

        if ($byDay !== null) {
            $parts[] = 'BYDAY='.$byDay;
        }

        $ending = $this->rruleEnding($request, $timezone);

        if ($ending !== null) {
            $parts[] = $ending;
        }

        return implode(';', $parts);
    }

    /**
     * The weekday part: which days a weekly rule falls on, or which weekday
     * position a monthly rule repeats at.
     */
    private function byDay(FormRequest $request, string $frequency, string $timezone, CarbonImmutable $startsAt): ?string
    {
        if ($frequency === 'weekly') {
            $days = array_values(array_filter(
                (array) $request->input('byday', []),
                fn (mixed $day): bool => is_string($day) && in_array($day, self::WEEKDAYS, true),
            ));

            return $days === [] ? null : implode(',', $days);
        }

        if ($frequency !== 'monthly' || $request->string('monthly_mode')->toString() !== 'weekday') {
            return null;
        }

        // Read the position off the event's own start, in its own zone: "the
        // second Tuesday" is a fact about the date the user picked.
        $local = $startsAt->setTimezone($timezone);
        $position = (int) ceil($local->day / 7);

        // A fifth weekday does not happen every month, and someone choosing it
        // means the last one.
        return ($position >= 5 ? -1 : $position).self::WEEKDAYS[$local->dayOfWeek];
    }

    /**
     * How the series stops: after a number of occurrences, on a date, or never.
     */
    private function rruleEnding(FormRequest $request, string $timezone): ?string
    {
        $ends = $request->string('ends')->toString();

        if ($ends === 'never') {
            return null;
        }

        if ($ends === 'count' || ($ends === '' && $request->filled('count'))) {
            return $request->filled('count') ? 'COUNT='.$request->integer('count') : null;
        }

        if (! $request->filled('until')) {
            return null;
        }

        return 'UNTIL='.CarbonImmutable::parse($request->string('until')->toString(), $timezone)
            ->endOfDay()
            ->utc()
            ->format('Ymd\THis\Z');
    }
}
