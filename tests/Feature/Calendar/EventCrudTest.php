<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;

it('creates a timed event, converting local time to UTC', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'Kickoff',
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => '2026-07-20T09:00',
            'ends_at' => '2026-07-20T10:00',
        ])
        ->assertRedirect();

    $event = Event::query()->firstOrFail();
    expect($event->title)->toBe('Kickoff')
        ->and($event->timezone)->toBe('Europe/Amsterdam')
        // 09:00 Amsterdam (+02:00) is 07:00 UTC.
        ->and($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-07-20 07:00');
});

it('stores an all-day event as an exclusive midnight-UTC span', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'Holiday',
            'all_day' => true,
            'starts_at' => '2026-07-20',
            'ends_at' => '2026-07-20',
        ])
        ->assertRedirect();

    $event = Event::query()->firstOrFail();
    expect($event->all_day)->toBeTrue()
        ->and($event->timezone)->toBe('UTC')
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe('2026-07-20 00:00')
        ->and($event->ends_at->format('Y-m-d H:i'))->toBe('2026-07-21 00:00');
});

it('rejects creating an event on a read-only mirrored calendar', function () {
    $user = User::factory()->create();
    $mirrored = Calendar::factory()->for($user)->mirrored()->create();

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $mirrored->id,
            'title' => 'Nope',
            'all_day' => false,
            'timezone' => 'UTC',
            'starts_at' => '2026-07-20T09:00',
            'ends_at' => '2026-07-20T10:00',
        ])
        ->assertSessionHasErrors('calendar_id');

    expect(Event::count())->toBe(0);
});

it('rejects creating an event on another user\'s calendar', function () {
    $user = User::factory()->create();
    $othersCalendar = Calendar::factory()->create(['is_writable' => true]);

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $othersCalendar->id,
            'title' => 'Nope',
            'all_day' => false,
            'timezone' => 'UTC',
            'starts_at' => '2026-07-20T09:00',
            'ends_at' => '2026-07-20T10:00',
        ])
        ->assertSessionHasErrors('calendar_id');
});

it('rejects an end that is not after the start for a timed event', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'Backwards',
            'all_day' => false,
            'timezone' => 'UTC',
            'starts_at' => '2026-07-20T10:00',
            'ends_at' => '2026-07-20T09:00',
        ])
        ->assertSessionHasErrors('ends_at');
});

it('updates an event', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);
    $event = Event::factory()->for($calendar)->create(['title' => 'Old']);

    $this->actingAs($user)
        ->patch(route('events.update', $event), [
            'calendar_id' => $calendar->id,
            'title' => 'New',
            'all_day' => false,
            'timezone' => 'UTC',
            'starts_at' => '2026-07-20T09:00',
            'ends_at' => '2026-07-20T10:00',
        ])
        ->assertRedirect();

    expect($event->fresh()->title)->toBe('New');
});

it('forbids updating an event on a read-only calendar', function () {
    $user = User::factory()->create();
    $mirrored = Calendar::factory()->for($user)->mirrored()->create();
    $event = Event::factory()->for($mirrored)->external()->create();
    $writable = Calendar::factory()->for($user)->create(['is_writable' => true]);

    $this->actingAs($user)
        ->patch(route('events.update', $event), [
            'calendar_id' => $writable->id,
            'title' => 'Hijack',
            'all_day' => false,
            'timezone' => 'UTC',
            'starts_at' => '2026-07-20T09:00',
            'ends_at' => '2026-07-20T10:00',
        ])
        ->assertForbidden();
});

it('deletes an event', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);
    $event = Event::factory()->for($calendar)->create();

    $this->actingAs($user)
        ->delete(route('events.destroy', $event))
        ->assertRedirect();

    expect(Event::find($event->id))->toBeNull();
});

it('forbids deleting an event on a read-only calendar', function () {
    $user = User::factory()->create();
    $mirrored = Calendar::factory()->for($user)->mirrored()->create();
    $event = Event::factory()->for($mirrored)->external()->create();

    $this->actingAs($user)
        ->delete(route('events.destroy', $event))
        ->assertForbidden();

    expect(Event::find($event->id))->not->toBeNull();
});
