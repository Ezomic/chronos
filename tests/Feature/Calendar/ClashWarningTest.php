<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Services\Calendar\ClashDetector;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

function clashUser(): User
{
    return User::factory()->create();
}

function existingEvent(User $user, string $start, string $end, array $attributes = []): Event
{
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    return Event::factory()->for($calendar)->create([
        'title' => 'Existing',
        'starts_at' => CarbonImmutable::parse($start),
        'ends_at' => CarbonImmutable::parse($end),
        'timezone' => 'UTC',
        ...$attributes,
    ]);
}

/** @param array<string, mixed> $overrides */
function saveEvent(User $user, array $overrides = []): TestResponse
{
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    return test()->actingAs($user)->post(route('events.store'), [
        'calendar_id' => $calendar->id,
        'title' => 'New thing',
        'all_day' => false,
        'timezone' => 'UTC',
        'starts_at' => '2026-07-20T09:30',
        'ends_at' => '2026-07-20T10:30',
        'frequency' => 'none',
        ...$overrides,
    ]);
}

function clashesFor(Event $event): array
{
    return app(ClashDetector::class)->for($event);
}

it('warns when a new event overlaps an existing one', function () {
    $user = clashUser();
    existingEvent($user, '2026-07-20T09:00:00Z', '2026-07-20T10:00:00Z');

    saveEvent($user)->assertRedirect();

    $saved = Event::query()->where('title', 'New thing')->firstOrFail();

    expect(clashesFor($saved))->toHaveCount(1)
        ->and(clashesFor($saved)[0])->toContain('Existing');
});

it('saves the event anyway', function () {
    $user = clashUser();
    existingEvent($user, '2026-07-20T09:00:00Z', '2026-07-20T10:00:00Z');

    saveEvent($user)->assertRedirect()->assertSessionHasNoErrors();

    expect(Event::query()->where('title', 'New thing')->exists())->toBeTrue();
});

it('says nothing about back-to-back events', function () {
    $user = clashUser();
    existingEvent($user, '2026-07-20T08:30:00Z', '2026-07-20T09:30:00Z');

    // Starts exactly as the other ends.
    saveEvent($user, ['starts_at' => '2026-07-20T09:30', 'ends_at' => '2026-07-20T10:30']);

    $saved = Event::query()->where('title', 'New thing')->firstOrFail();

    expect(clashesFor($saved))->toBe([]);
});

it('says nothing when the calendar is clear', function () {
    $user = clashUser();
    existingEvent($user, '2026-07-21T09:00:00Z', '2026-07-21T10:00:00Z');

    saveEvent($user);

    expect(clashesFor(Event::query()->where('title', 'New thing')->firstOrFail()))->toBe([]);
});

it('counts an all-day event as covering the whole day', function () {
    $user = clashUser();

    // The exclusive midnight-UTC span CHRON-48 documents.
    existingEvent($user, '2026-07-20T00:00:00Z', '2026-07-21T00:00:00Z', [
        'title' => 'Conference',
        'all_day' => true,
    ]);

    saveEvent($user);

    $saved = Event::query()->where('title', 'New thing')->firstOrFail();

    expect(clashesFor($saved))->toHaveCount(1)
        ->and(clashesFor($saved)[0])->toContain('Conference')
        ->and(clashesFor($saved)[0])->toContain('all day');
});

it('counts an occurrence of a recurring series', function () {
    $user = clashUser();

    // Anchored a fortnight before the new event, still generating into it.
    existingEvent($user, '2026-07-06T09:00:00Z', '2026-07-06T10:00:00Z', [
        'title' => 'Standup',
        'rrule' => 'FREQ=WEEKLY',
    ]);

    saveEvent($user);

    $saved = Event::query()->where('title', 'New thing')->firstOrFail();

    expect(clashesFor($saved))->toHaveCount(1)
        ->and(clashesFor($saved)[0])->toContain('Standup')
        // The occurrence that clashes, not the series anchor.
        ->and(clashesFor($saved)[0])->toContain('20 Jul');
});

it('counts a mirrored event, which is still a real commitment', function () {
    $user = clashUser();
    $mirrored = Calendar::factory()->for($user)->mirrored()->create();

    Event::factory()->for($mirrored)->create([
        'title' => 'Someone else booked this',
        'starts_at' => CarbonImmutable::parse('2026-07-20T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-20T10:00:00Z'),
        'timezone' => 'UTC',
    ]);

    saveEvent($user);

    expect(clashesFor(Event::query()->where('title', 'New thing')->firstOrFail()))
        ->toHaveCount(1);
});

it('ignores events on a hidden calendar', function () {
    $user = clashUser();
    $hidden = Calendar::factory()->for($user)->create(['is_visible' => false]);

    Event::factory()->for($hidden)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-20T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-20T10:00:00Z'),
        'timezone' => 'UTC',
    ]);

    saveEvent($user);

    expect(clashesFor(Event::query()->where('title', 'New thing')->firstOrFail()))->toBe([]);
});

it('ignores another user events', function () {
    $user = clashUser();
    $stranger = clashUser();

    existingEvent($stranger, '2026-07-20T09:00:00Z', '2026-07-20T10:00:00Z');

    saveEvent($user);

    expect(clashesFor(Event::query()->where('title', 'New thing')->firstOrFail()))->toBe([]);
});

it('does not report a deleted event', function () {
    $user = clashUser();
    $existing = existingEvent($user, '2026-07-20T09:00:00Z', '2026-07-20T10:00:00Z');
    $existing->delete();

    saveEvent($user);

    expect(clashesFor(Event::query()->where('title', 'New thing')->firstOrFail()))->toBe([]);
});

it('does not report a series against its own override', function () {
    $user = clashUser();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    $series = Event::factory()->for($calendar)->create([
        'title' => 'Standup',
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:30:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY',
        'excluded_dates' => ['2026-07-13 09:00:00'],
    ]);

    $override = Event::factory()->for($calendar)->create([
        'title' => 'Standup (moved)',
        // Overlaps where the 13 July occurrence would have been.
        'starts_at' => CarbonImmutable::parse('2026-07-13T09:15:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-13T09:45:00Z'),
        'timezone' => 'UTC',
        'overrides_event_id' => $series->id,
        'overrides_starts_at' => CarbonImmutable::parse('2026-07-13T09:00:00Z'),
    ]);

    expect(clashesFor($override))->toBe([])
        ->and(clashesFor($series))->toBe([]);
});

it('caps how many clashes it names', function () {
    $user = clashUser();

    foreach (range(1, 6) as $index) {
        existingEvent($user, '2026-07-20T09:00:00Z', '2026-07-20T11:00:00Z', [
            'title' => "Existing {$index}",
        ]);
    }

    saveEvent($user);

    expect(clashesFor(Event::query()->where('title', 'New thing')->firstOrFail()))
        ->toHaveCount(3);
});

it('flashes the clash to the page as a warning', function () {
    $user = clashUser();
    existingEvent($user, '2026-07-20T09:00:00Z', '2026-07-20T10:00:00Z');

    saveEvent($user);

    $toast = session('inertia.flash_data')['toast'] ?? null;

    expect($toast)->not->toBeNull()
        ->and($toast['type'])->toBe('warning')
        ->and($toast['message'])->toContain('overlaps')
        ->and($toast['message'])->toContain('Existing');
});

it('flashes nothing when there is no clash', function () {
    $user = clashUser();

    saveEvent($user);

    expect(session('inertia.flash_data')['toast'] ?? null)->toBeNull();
});
