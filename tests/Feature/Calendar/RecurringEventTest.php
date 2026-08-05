<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

it('stores an RRULE when an event is created with a frequency', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'Standup',
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => '2026-07-06T09:00',
            'ends_at' => '2026-07-06T09:15',
            'frequency' => 'weekly',
            'until' => '2026-07-27',
        ])
        ->assertRedirect();

    $event = Event::query()->firstOrFail();
    expect($event->rrule)->toContain('FREQ=WEEKLY')
        ->and($event->rrule)->toContain('UNTIL=20260727');
});

it('does not set an RRULE when frequency is none', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'One-off',
            'all_day' => false,
            'timezone' => 'UTC',
            'starts_at' => '2026-07-06T09:00',
            'ends_at' => '2026-07-06T10:00',
            'frequency' => 'none',
        ]);

    expect(Event::query()->firstOrFail()->rrule)->toBeNull();
});

it('expands a bounded weekly series into its occurrences in the window', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();

    Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'rrule' => 'FREQ=WEEKLY;UNTIL=20260727T235959Z',
    ]);

    // July 2026 month grid; the series repeats Mondays 06/13/20/27 then stops.
    $this->actingAs($user)
        ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-07-15']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 4)
            ->where('events.0.rrule', 'FREQ=WEEKLY;UNTIL=20260727T235959Z')
            ->where('events.0.series_starts_at', fn ($v) => str_starts_with((string) $v, '2026-07-06'))
            // Each occurrence has a distinct key but the same underlying id.
            ->where('events.0.id', fn ($id) => is_int($id)));
});

it('gives each occurrence of a series a distinct key but a shared id', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();

    $master = Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'rrule' => 'FREQ=WEEKLY;UNTIL=20260727T235959Z',
    ]);

    $response = $this->actingAs($user)
        ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-07-15']));

    $events = $response->viewData('page')['props']['events'];
    $keys = collect($events)->pluck('key');
    $ids = collect($events)->pluck('id')->unique();

    expect($keys->unique())->toHaveCount(4)
        ->and($ids->all())->toBe([$master->id]);
});

it('clears recurrence when a series is edited to not repeat', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);
    $event = Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $this->actingAs($user)
        ->patch(route('events.update', $event), [
            'calendar_id' => $calendar->id,
            'title' => 'No longer repeating',
            'all_day' => false,
            'timezone' => 'UTC',
            'starts_at' => '2026-07-06T09:00',
            'ends_at' => '2026-07-06T09:15',
            'frequency' => 'none',
        ])
        ->assertRedirect();

    expect($event->fresh()->rrule)->toBeNull();
});

it('keeps the local start time of a series across the autumn DST change', function () {
    $calendar = Calendar::factory()->create();

    $event = Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-05 09:00', 'Europe/Amsterdam')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-10-05 09:30', 'Europe/Amsterdam')->utc(),
        'timezone' => 'Europe/Amsterdam',
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $occurrences = app(RecurrenceExpander::class)->expand(
        $event,
        CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        CarbonImmutable::parse('2026-11-09T00:00:00Z'),
    );

    $local = array_map(
        fn (array $occurrence) => $occurrence['starts_at']->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'),
        $occurrences,
    );

    // Amsterdam leaves summer time on 25 October 2026, between the third and
    // fourth occurrence. The wall-clock time has to stay put.
    expect($local)->toBe([
        '2026-10-05 09:00',
        '2026-10-12 09:00',
        '2026-10-19 09:00',
        '2026-10-26 09:00',
        '2026-11-02 09:00',
    ]);

    // Which means the underlying UTC instant moves by the offset change.
    expect($occurrences[0]['starts_at']->format('H:i'))->toBe('07:00')
        ->and($occurrences[3]['starts_at']->format('H:i'))->toBe('08:00');
});

it('preserves each occurrence duration across the DST change', function () {
    $calendar = Calendar::factory()->create();

    $event = Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-19 09:00', 'Europe/Amsterdam')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-10-19 10:30', 'Europe/Amsterdam')->utc(),
        'timezone' => 'Europe/Amsterdam',
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $occurrences = app(RecurrenceExpander::class)->expand(
        $event,
        CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        CarbonImmutable::parse('2026-11-09T00:00:00Z'),
    );

    foreach ($occurrences as $occurrence) {
        expect($occurrence['starts_at']->diffInMinutes($occurrence['ends_at']))->toBe(90.0);
    }
});

it('leaves an all-day series on its dates', function () {
    $calendar = Calendar::factory()->create();

    $event = Event::factory()->for($calendar)->allDay()->create([
        'starts_at' => CarbonImmutable::parse('2026-10-19 00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-20 00:00', 'UTC'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $occurrences = app(RecurrenceExpander::class)->expand(
        $event,
        CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        CarbonImmutable::parse('2026-11-09T00:00:00Z'),
    );

    expect(array_map(fn (array $o) => $o['starts_at']->format('Y-m-d H:i'), $occurrences))
        ->toBe(['2026-10-19 00:00', '2026-10-26 00:00', '2026-11-02 00:00', '2026-11-09 00:00']);
});

it('deletes the whole series when the master is deleted', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);
    $event = Event::factory()->for($calendar)->create(['rrule' => 'FREQ=DAILY']);

    $this->actingAs($user)
        ->delete(route('events.destroy', $event))
        ->assertRedirect();

    expect(Event::find($event->id))->toBeNull();
});
