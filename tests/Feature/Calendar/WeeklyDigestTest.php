<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

it('emails a weekly digest to a user with events this week', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $weekStart = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);
    Event::factory()->for($calendar)->create([
        'starts_at' => $weekStart->addDays(1)->setTime(9, 0),
        'ends_at' => $weekStart->addDays(1)->setTime(10, 0),
    ]);

    $this->artisan('chronos:weekly-digest')->assertSuccessful();

    Notification::assertSentTo($user, WeeklyDigestNotification::class);
});

it('includes a recurring occurrence in the digest', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $weekStart = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);
    Event::factory()->for($calendar)->create([
        'starts_at' => $weekStart->setTime(9, 0),
        'ends_at' => $weekStart->setTime(9, 15),
        'rrule' => 'FREQ=DAILY',
    ]);

    $this->artisan('chronos:weekly-digest')->assertSuccessful();

    Notification::assertSentTo($user, WeeklyDigestNotification::class);
});

it('does not send a digest when the week has no events', function () {
    Notification::fake();

    $user = User::factory()->create(); // default calendar, no events

    $this->artisan('chronos:weekly-digest')->assertSuccessful();

    Notification::assertNotSentTo($user, WeeklyDigestNotification::class);
});
