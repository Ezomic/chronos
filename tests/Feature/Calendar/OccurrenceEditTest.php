<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * A weekly Monday 09:00 series anchored on 6 July 2026, running four Mondays
 * through July.
 */
function weeklySeries(User $user): Event
{
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    return Event::factory()->for($calendar)->create([
        'title' => 'Standup',
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;UNTIL=20260727T235959Z',
    ]);
}

function julyGrid(User $user): TestResponse
{
    return test()->actingAs($user)
        ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-07-15']));
}

it('skips one occurrence when a single one is deleted', function () {
    $user = User::factory()->create();
    $series = weeklySeries($user);

    $this->actingAs($user)
        ->delete(route('events.destroy', $series), [
            'scope' => 'occurrence',
            'occurrence_starts_at' => '2026-07-13T09:00:00Z',
        ])
        ->assertRedirect();

    expect(Event::find($series->id))->not->toBeNull()
        ->and($series->fresh()->excluded_dates)->toBe(['2026-07-13 09:00:00']);

    julyGrid($user)->assertInertia(fn (AssertableInertia $page) => $page
        ->has('events', 3)
        ->where('events', fn ($events) => collect($events)
            ->pluck('starts_at')
            ->every(fn (string $start) => ! str_starts_with($start, '2026-07-13'))));
});

it('deletes the whole series when the scope says so', function () {
    $user = User::factory()->create();
    $series = weeklySeries($user);

    $this->actingAs($user)
        ->delete(route('events.destroy', $series))
        ->assertRedirect();

    expect(Event::find($series->id))->toBeNull();
    julyGrid($user)->assertInertia(fn (AssertableInertia $page) => $page->has('events', 0));
});

it('edits one occurrence into an event of its own', function () {
    $user = User::factory()->create();
    $series = weeklySeries($user);

    $this->actingAs($user)
        ->patch(route('events.update', $series), [
            'calendar_id' => $series->calendar_id,
            'title' => 'Standup (moved)',
            'all_day' => false,
            'timezone' => 'UTC',
            'starts_at' => '2026-07-13T14:00',
            'ends_at' => '2026-07-13T14:30',
            'frequency' => 'none',
            'scope' => 'occurrence',
            'occurrence_starts_at' => '2026-07-13T09:00:00Z',
        ])
        ->assertRedirect();

    $override = Event::query()->where('overrides_event_id', $series->id)->firstOrFail();

    expect($override->title)->toBe('Standup (moved)')
        ->and($override->rrule)->toBeNull()
        ->and($override->overrides_starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-07-13 09:00')
        ->and($override->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-07-13 14:00')
        // The series itself is untouched.
        ->and($series->fresh()->title)->toBe('Standup')
        ->and($series->fresh()->rrule)->toBe('FREQ=WEEKLY;UNTIL=20260727T235959Z');
});

it('shows the override in place of the occurrence it replaces', function () {
    $user = User::factory()->create();
    $series = weeklySeries($user);

    $this->actingAs($user)->patch(route('events.update', $series), [
        'calendar_id' => $series->calendar_id,
        'title' => 'Standup (moved)',
        'all_day' => false,
        'timezone' => 'UTC',
        'starts_at' => '2026-07-13T14:00',
        'ends_at' => '2026-07-13T14:30',
        'frequency' => 'none',
        'scope' => 'occurrence',
        'occurrence_starts_at' => '2026-07-13T09:00:00Z',
    ]);

    $events = collect(julyGrid($user)->viewData('page')['props']['events']);

    // Still four: three generated, one overridden. Never both for 13 July.
    expect($events)->toHaveCount(4)
        ->and($events->where('title', 'Standup (moved)')->count())->toBe(1)
        ->and($events->filter(fn ($e) => str_starts_with($e['starts_at'], '2026-07-13'))->count())->toBe(1)
        ->and($events->firstWhere('title', 'Standup (moved)')['starts_at'])->toStartWith('2026-07-13T14:00');
});

it('re-edits an occurrence without stacking overrides', function () {
    $user = User::factory()->create();
    $series = weeklySeries($user);

    $payload = fn (string $title, string $hour): array => [
        'calendar_id' => $series->calendar_id,
        'title' => $title,
        'all_day' => false,
        'timezone' => 'UTC',
        'starts_at' => "2026-07-13T{$hour}:00",
        'ends_at' => "2026-07-13T{$hour}:30",
        'frequency' => 'none',
        'scope' => 'occurrence',
        'occurrence_starts_at' => '2026-07-13T09:00:00Z',
    ];

    $this->actingAs($user)->patch(route('events.update', $series), $payload('First move', '14'));
    $this->actingAs($user)->patch(route('events.update', $series), $payload('Second move', '16'));

    $overrides = Event::query()->where('overrides_event_id', $series->id)->get();

    expect($overrides)->toHaveCount(1)
        ->and($overrides->first()->title)->toBe('Second move');
});

it('takes overrides with the series when the series is deleted', function () {
    $user = User::factory()->create();
    $series = weeklySeries($user);

    $this->actingAs($user)->patch(route('events.update', $series), [
        'calendar_id' => $series->calendar_id,
        'title' => 'Standup (moved)',
        'all_day' => false,
        'timezone' => 'UTC',
        'starts_at' => '2026-07-13T14:00',
        'ends_at' => '2026-07-13T14:30',
        'frequency' => 'none',
        'scope' => 'occurrence',
        'occurrence_starts_at' => '2026-07-13T09:00:00Z',
    ]);

    expect(Event::query()->count())->toBe(2);

    $this->actingAs($user)->delete(route('events.destroy', $series));

    expect(Event::query()->count())->toBe(0);
});

it('does not let the series regenerate an occurrence whose override is deleted', function () {
    $user = User::factory()->create();
    $series = weeklySeries($user);

    $this->actingAs($user)->patch(route('events.update', $series), [
        'calendar_id' => $series->calendar_id,
        'title' => 'Standup (moved)',
        'all_day' => false,
        'timezone' => 'UTC',
        'starts_at' => '2026-07-13T14:00',
        'ends_at' => '2026-07-13T14:30',
        'frequency' => 'none',
        'scope' => 'occurrence',
        'occurrence_starts_at' => '2026-07-13T09:00:00Z',
    ]);

    $override = Event::query()->where('overrides_event_id', $series->id)->firstOrFail();

    $this->actingAs($user)->delete(route('events.destroy', $override))->assertRedirect();

    expect(Event::find($override->id))->toBeNull()
        ->and($series->fresh()->excluded_dates)->toBe(['2026-07-13 09:00:00']);

    // Three left, and 13 July is gone rather than back at 09:00.
    julyGrid($user)->assertInertia(fn (AssertableInertia $page) => $page->has('events', 3));
});

it('drops a pending override when the occurrence is deleted outright', function () {
    $user = User::factory()->create();
    $series = weeklySeries($user);

    $this->actingAs($user)->patch(route('events.update', $series), [
        'calendar_id' => $series->calendar_id,
        'title' => 'Standup (moved)',
        'all_day' => false,
        'timezone' => 'UTC',
        'starts_at' => '2026-07-13T14:00',
        'ends_at' => '2026-07-13T14:30',
        'frequency' => 'none',
        'scope' => 'occurrence',
        'occurrence_starts_at' => '2026-07-13T09:00:00Z',
    ]);

    $this->actingAs($user)->delete(route('events.destroy', $series), [
        'scope' => 'occurrence',
        'occurrence_starts_at' => '2026-07-13T09:00:00Z',
    ]);

    expect(Event::query()->where('overrides_event_id', $series->id)->count())->toBe(0)
        ->and($series->fresh()->excluded_dates)->toBe(['2026-07-13 09:00:00']);

    julyGrid($user)->assertInertia(fn (AssertableInertia $page) => $page->has('events', 3));
});

it('keeps expanding a series that has no exceptions', function () {
    $user = User::factory()->create();
    $series = weeklySeries($user);

    $occurrences = app(RecurrenceExpander::class)->expand(
        $series->fresh(),
        CarbonImmutable::parse('2026-07-01T00:00:00Z'),
        CarbonImmutable::parse('2026-07-31T00:00:00Z'),
    );

    expect($occurrences)->toHaveCount(4);
});

it('does not remind for a skipped occurrence', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    $start = CarbonImmutable::now()->addMinutes(10)->startOfMinute();

    $series = Event::factory()->for($calendar)->create([
        'starts_at' => $start,
        'ends_at' => $start->addMinutes(15),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY',
        'reminder_minutes' => 15,
        'excluded_dates' => [$start->utc()->format('Y-m-d H:i:s')],
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    expect($series->fresh()->reminder_sent_for)->toBeNull();
});

it('reminds an overridden occurrence on its own time', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    $original = CarbonImmutable::now()->addMinutes(10)->startOfMinute();

    $series = Event::factory()->for($calendar)->create([
        'starts_at' => $original,
        'ends_at' => $original->addMinutes(15),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=DAILY',
        'reminder_minutes' => 15,
    ]);

    // Moved five minutes earlier, so its own reminder is due now.
    Event::factory()->for($calendar)->create([
        'starts_at' => $original->subMinutes(5),
        'ends_at' => $original->addMinutes(10),
        'timezone' => 'UTC',
        'rrule' => null,
        'overrides_event_id' => $series->id,
        'overrides_starts_at' => $original,
        'reminder_minutes' => 15,
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    // Once, for the override, not for the occurrence it replaced.
    Notification::assertSentToTimes($user, EventReminderNotification::class, 1);
    expect($series->fresh()->reminder_sent_for)->toBeNull();
});
