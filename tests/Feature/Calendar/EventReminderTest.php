<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use Illuminate\Support\Facades\Notification;

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
            'reminder_minutes' => 10,
        ])
        ->assertRedirect();

    expect(Event::query()->firstOrFail()->reminder_minutes)->toBe(10);
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
            'reminder_minutes' => 7,
        ])
        ->assertSessionHasErrors('reminder_minutes');
});

it('re-arms a spent reminder when the reminder changes on update', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $event = Event::factory()->for($calendar)->create([
        'reminder_minutes' => 10,
        'reminder_sent_at' => now(),
    ]);

    $this->actingAs($user)
        ->patch(route('events.update', $event), [
            'calendar_id' => $calendar->id,
            'title' => $event->title,
            'all_day' => false,
            'timezone' => 'Europe/Amsterdam',
            'starts_at' => $event->starts_at->timezone('Europe/Amsterdam')->format('Y-m-d\TH:i'),
            'ends_at' => $event->ends_at->timezone('Europe/Amsterdam')->format('Y-m-d\TH:i'),
            'reminder_minutes' => 30,
        ])
        ->assertRedirect();

    $event->refresh();
    expect($event->reminder_minutes)->toBe(30)
        ->and($event->reminder_sent_at)->toBeNull();
});

it('notifies the owner when a reminder is due and stamps it once', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $event = Event::factory()->for($calendar)->create([
        'starts_at' => now()->addMinutes(10),
        'ends_at' => now()->addMinutes(40),
        'reminder_minutes' => 15, // due 5 minutes ago
        'reminder_sent_at' => null,
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertSentTo($user, EventReminderNotification::class);
    expect($event->refresh()->reminder_sent_at)->not->toBeNull();

    // A second run must not notify again.
    Notification::fake();
    $this->artisan('chronos:send-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

it('does not send a reminder that is not yet due', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    Event::factory()->for($calendar)->create([
        'starts_at' => now()->addHours(3),
        'ends_at' => now()->addHours(4),
        'reminder_minutes' => 10, // due in ~2h50m
        'reminder_sent_at' => null,
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('does not remind for an event that already started', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    Event::factory()->for($calendar)->create([
        'starts_at' => now()->subMinutes(5),
        'ends_at' => now()->addMinutes(25),
        'reminder_minutes' => 10,
        'reminder_sent_at' => null,
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('sends a reminder for a due recurring occurrence and stamps it once', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $event = Event::factory()->for($calendar)->create([
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addMinutes(35),
        'reminder_minutes' => 10, // due 5 minutes ago
        'reminder_sent_at' => null,
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertSentTo($user, EventReminderNotification::class);
    expect($event->refresh()->reminder_sent_for)->not->toBeNull();

    // The same occurrence must not remind twice.
    Notification::fake();
    $this->artisan('chronos:send-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

it('does not send a recurring reminder that is not yet due', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    Event::factory()->for($calendar)->create([
        'starts_at' => now()->addHours(5),
        'ends_at' => now()->addHours(6),
        'reminder_minutes' => 10, // the next occurrence is hours away
        'reminder_sent_at' => null,
        'rrule' => 'FREQ=WEEKLY',
    ]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});
