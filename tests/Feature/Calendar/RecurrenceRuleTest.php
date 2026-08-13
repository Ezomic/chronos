<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;

/**
 * Create an event through the controller and hand back the rule it stored.
 *
 * @param  array<string, mixed>  $recurrence
 */
function storedRrule(array $recurrence, string $startsAt = '2026-07-06T09:00', string $timezone = 'UTC'): ?string
{
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    test()->actingAs($user)->post(route('events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'Standup',
        'all_day' => false,
        'timezone' => $timezone,
        'starts_at' => $startsAt,
        'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes(15)->format('Y-m-d\TH:i'),
        ...$recurrence,
    ])->assertRedirect();

    return Event::query()->latest('id')->firstOrFail()->rrule;
}

it('repeats at an interval', function () {
    expect(storedRrule(['frequency' => 'weekly', 'interval' => 2]))
        ->toBe('FREQ=WEEKLY;INTERVAL=2');
});

it('leaves an interval of one out of the rule', function () {
    expect(storedRrule(['frequency' => 'weekly', 'interval' => 1]))
        ->toBe('FREQ=WEEKLY');
});

it('repeats on chosen weekdays', function () {
    expect(storedRrule(['frequency' => 'weekly', 'byday' => ['MO', 'WE', 'FR']]))
        ->toBe('FREQ=WEEKLY;BYDAY=MO,WE,FR');
});

it('ignores a weekday set on a rule that is not weekly', function () {
    expect(storedRrule(['frequency' => 'daily', 'byday' => ['MO']]))
        ->toBe('FREQ=DAILY');
});

it('stops after a number of occurrences', function () {
    expect(storedRrule(['frequency' => 'weekly', 'ends' => 'count', 'count' => 10]))
        ->toBe('FREQ=WEEKLY;COUNT=10');
});

it('repeats forever when the rule says never', function () {
    expect(storedRrule(['frequency' => 'weekly', 'ends' => 'never', 'until' => '2026-08-01']))
        ->toBe('FREQ=WEEKLY');
});

it('still accepts an until with no explicit ends, as before', function () {
    expect(storedRrule(['frequency' => 'weekly', 'until' => '2026-07-27']))
        ->toContain('UNTIL=20260727');
});

it('combines interval, weekdays and a count', function () {
    expect(storedRrule([
        'frequency' => 'weekly',
        'interval' => 2,
        'byday' => ['TU', 'TH'],
        'ends' => 'count',
        'count' => 6,
    ]))->toBe('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH;COUNT=6');
});

it('repeats monthly on the weekday position of its own start', function () {
    // 9 July 2026 is the second Thursday of the month.
    expect(storedRrule(
        ['frequency' => 'monthly', 'monthly_mode' => 'weekday'],
        '2026-07-09T09:00',
    ))->toBe('FREQ=MONTHLY;BYDAY=2TH');
});

it('reads a fifth weekday as the last one', function () {
    // 29 July 2026 is the fifth Wednesday, which most months do not have.
    expect(storedRrule(
        ['frequency' => 'monthly', 'monthly_mode' => 'weekday'],
        '2026-07-29T09:00',
    ))->toBe('FREQ=MONTHLY;BYDAY=-1WE');
});

it('repeats monthly on the date when that is the mode', function () {
    expect(storedRrule(
        ['frequency' => 'monthly', 'monthly_mode' => 'day_of_month'],
        '2026-07-09T09:00',
    ))->toBe('FREQ=MONTHLY');
});

it('reads the weekday position in the event own timezone', function () {
    // 23:30 on 7 July in Amsterdam is 21:30 UTC the same day: still the first
    // Tuesday. Reading it in UTC could land on a different date entirely.
    expect(storedRrule(
        ['frequency' => 'monthly', 'monthly_mode' => 'weekday'],
        '2026-07-07T23:30',
        'Europe/Amsterdam',
    ))->toBe('FREQ=MONTHLY;BYDAY=1TU');
});

it('rejects an interval outside the allowed range', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    $this->actingAs($user)->post(route('events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'Standup',
        'all_day' => false,
        'timezone' => 'UTC',
        'starts_at' => '2026-07-06T09:00',
        'ends_at' => '2026-07-06T09:15',
        'frequency' => 'weekly',
        'interval' => 0,
    ])->assertSessionHasErrors('interval');
});

it('rejects a weekday that is not a weekday', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    $this->actingAs($user)->post(route('events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'Standup',
        'all_day' => false,
        'timezone' => 'UTC',
        'starts_at' => '2026-07-06T09:00',
        'ends_at' => '2026-07-06T09:15',
        'frequency' => 'weekly',
        'byday' => ['MONDAY'],
    ])->assertSessionHasErrors('byday.0');
});

it('expands a fortnightly rule to every other week', function () {
    $event = Event::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;INTERVAL=2',
    ]);

    $starts = collect(app(RecurrenceExpander::class)->expand(
        $event,
        CarbonImmutable::parse('2026-07-01T00:00:00Z'),
        CarbonImmutable::parse('2026-08-05T00:00:00Z'),
    ))->map(fn (array $o) => $o['starts_at']->format('Y-m-d'));

    expect($starts->all())->toBe(['2026-07-06', '2026-07-20', '2026-08-03']);
});

it('expands a weekday set to each chosen day', function () {
    $event = Event::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
    ]);

    $starts = collect(app(RecurrenceExpander::class)->expand(
        $event,
        CarbonImmutable::parse('2026-07-06T00:00:00Z'),
        CarbonImmutable::parse('2026-07-13T00:00:00Z'),
    ))->map(fn (array $o) => $o['starts_at']->format('Y-m-d'));

    // Monday 6th, Wednesday 8th, Friday 10th.
    expect($starts->all())->toBe(['2026-07-06', '2026-07-08', '2026-07-10']);
});

it('expands a count to exactly that many occurrences', function () {
    $event = Event::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;COUNT=3',
    ]);

    $occurrences = app(RecurrenceExpander::class)->expand(
        $event,
        CarbonImmutable::parse('2026-07-01T00:00:00Z'),
        CarbonImmutable::parse('2026-12-31T00:00:00Z'),
    );

    expect($occurrences)->toHaveCount(3);
});

it('expands a monthly weekday position', function () {
    $event = Event::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-09T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-09T09:15:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=MONTHLY;BYDAY=2TH',
    ]);

    $starts = collect(app(RecurrenceExpander::class)->expand(
        $event,
        CarbonImmutable::parse('2026-07-01T00:00:00Z'),
        CarbonImmutable::parse('2026-10-01T00:00:00Z'),
    ))->map(fn (array $o) => $o['starts_at']->format('Y-m-d'));

    // Second Thursday of July, August and September 2026.
    expect($starts->all())->toBe(['2026-07-09', '2026-08-13', '2026-09-10']);
});

it('keeps the DST behaviour for a rule with an interval', function () {
    $event = Event::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-10-05 09:00', 'Europe/Amsterdam')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-10-05 09:30', 'Europe/Amsterdam')->utc(),
        'timezone' => 'Europe/Amsterdam',
        'rrule' => 'FREQ=WEEKLY;INTERVAL=2',
    ]);

    $local = collect(app(RecurrenceExpander::class)->expand(
        $event,
        CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        CarbonImmutable::parse('2026-11-20T00:00:00Z'),
    ))->map(fn (array $o) => $o['starts_at']->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'));

    // Across the 25 October transition, still 09:00 local. CHRON-49 holds.
    expect($local->all())->toBe([
        '2026-10-05 09:00',
        '2026-10-19 09:00',
        '2026-11-02 09:00',
        '2026-11-16 09:00',
    ]);
});
