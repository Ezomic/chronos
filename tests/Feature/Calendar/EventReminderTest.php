<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\EventReminder;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use Illuminate\Support\Facades\Notification;

/**
 * @param  array<int, int>  $minutes
 */
function eventWithReminders(Calendar $calendar, array $minutes, array $attributes = []): Event
{
    $event = Event::factory()->for($calendar)->create($attributes);

    foreach ($minutes as $value) {
        $event->reminders()->create(['minutes_before' => $value]);
    }

    return $event;
}

it('stores a reminder on a new event', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'Standup',
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => '2026-08-01T09:00',
            'ends_at' => '2026-08-01T09:15',
            'reminders' => [10],
        ])
        ->assertRedirect();

    expect(Event::query()->firstOrFail()->reminders->pluck('minutes_before')->all())->toBe([10]);
});

it('stores several reminders on one event', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'Dentist',
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => '2026-08-01T09:00',
            'ends_at' => '2026-08-01T09:15',
            'reminders' => [1440, 15],
        ])
        ->assertRedirect();

    expect(Event::query()->firstOrFail()->reminders->pluck('minutes_before')->sort()->values()->all())
        ->toBe([15, 1440]);
});

it('rejects a reminder outside the allowed choices', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'Bad reminder',
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => '2026-08-01T09:00',
            'ends_at' => '2026-08-01T09:15',
            'reminders' => [7],
        ])
        ->assertSessionHasErrors('reminders.0');
});

it('inherits the calendar default reminders', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['default_reminder_minutes' => [15, 1440]]);

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'Inherits',
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => '2026-08-01T09:00',
            'ends_at' => '2026-08-01T09:15',
        ])
        ->assertRedirect();

    expect(Event::query()->firstOrFail()->reminders->pluck('minutes_before')->sort()->values()->all())
        ->toBe([15, 1440]);
});

it('lets an event opt out of the calendar defaults', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['default_reminder_minutes' => [15]]);

    $this->actingAs($user)
        ->post(route('events.store'), [
            'calendar_id' => $calendar->id,
            'title' => 'No reminders please',
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => '2026-08-01T09:00',
            'ends_at' => '2026-08-01T09:15',
            'reminders' => [],
        ])
        ->assertRedirect();

    expect(Event::query()->firstOrFail()->reminders)->toHaveCount(0);
});

it('replaces the set on update and keeps delivery state for survivors', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    // Whole minutes, so the form round-trip does not shave off seconds and
    // count as a move.
    $event = eventWithReminders($calendar, [10, 30], [
        'starts_at' => now()->addDay()->startOfMinute(),
        'ends_at' => now()->addDay()->startOfMinute()->addHour(),
    ]);

    $event->reminders()->update(['sent_at' => now()]);

    $this->actingAs($user)
        ->patch(route('events.update', $event), [
            'calendar_id' => $calendar->id,
            'title' => $event->title,
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => $event->starts_at->timezone('Europe/Amsterdam')->format('Y-m-d\TH:i'),
            'ends_at' => $event->ends_at->timezone('Europe/Amsterdam')->format('Y-m-d\TH:i'),
            'reminders' => [30, 60],
        ])
        ->assertRedirect();

    $reminders = $event->fresh()->reminders->keyBy('minutes_before');

    expect($reminders->keys()->sort()->values()->all())->toBe([30, 60])
        // 30 survived untouched, so it must not remind again.
        ->and($reminders[30]->sent_at)->not->toBeNull()
        ->and($reminders[60]->sent_at)->toBeNull();
});

it('re-arms every reminder when the event moves', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $event = eventWithReminders($calendar, [10, 30]);

    $event->reminders()->update(['sent_at' => now()]);

    $this->actingAs($user)
        ->patch(route('events.update', $event), [
            'calendar_id' => $calendar->id,
            'title' => $event->title,
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => $event->starts_at->addDay()->timezone('Europe/Amsterdam')->format('Y-m-d\TH:i'),
            'ends_at' => $event->ends_at->addDay()->timezone('Europe/Amsterdam')->format('Y-m-d\TH:i'),
            'reminders' => [10, 30],
        ])
        ->assertRedirect();

    expect($event->fresh()->reminders->pluck('sent_at')->filter()->all())->toBe([]);
});

it('notifies the owner when a reminder is due and stamps it once', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $event = eventWithReminders($calendar, [15], [
        'starts_at' => now()->addMinutes(10),
        'ends_at' => now()->addMinutes(40),
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertSentTo($user, EventReminderNotification::class);
    expect($event->fresh()->reminders->first()->sent_at)->not->toBeNull();

    // A second run must not notify again.
    Notification::fake();
    $this->artisan('chronos:send-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

it('sends each reminder on one event at its own time', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();

    // Starts in ten minutes: the 15-minute reminder is due, the 5-minute one
    // is not.
    $event = eventWithReminders($calendar, [15, 5], [
        'starts_at' => now()->addMinutes(10),
        'ends_at' => now()->addMinutes(40),
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($user, EventReminderNotification::class, 1);

    $reminders = $event->fresh()->reminders->keyBy('minutes_before');

    expect($reminders[15]->sent_at)->not->toBeNull()
        ->and($reminders[5]->sent_at)->toBeNull();
});

it('does not send a reminder that is not yet due', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    eventWithReminders($calendar, [10], [
        'starts_at' => now()->addHours(3),
        'ends_at' => now()->addHours(4),
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('does not remind for an event that already started', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    eventWithReminders($calendar, [10], [
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addMinutes(25),
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('sends a reminder for a due recurring occurrence and stamps it once', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $event = eventWithReminders($calendar, [10], [
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addMinutes(35),
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertSentTo($user, EventReminderNotification::class);
    expect($event->fresh()->reminders->first()->sent_for)->not->toBeNull();

    // The same occurrence must not remind twice.
    Notification::fake();
    $this->artisan('chronos:send-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

it('does not send a recurring reminder that is not yet due', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    eventWithReminders($calendar, [10], [
        'starts_at' => now()->addHours(5),
        'ends_at' => now()->addHours(6),
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('deletes an event reminders with it', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);
    $event = eventWithReminders($calendar, [10, 30]);

    $event->forceDelete();

    expect(EventReminder::query()->count())->toBe(0);
});
