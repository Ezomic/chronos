<?php

use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

it('lists the upcoming events on the dashboard, sorted', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00'));

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    Event::factory()->for($calendar)->create([
        'title' => 'Review',
        'starts_at' => CarbonImmutable::parse('2026-08-06 10:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-08-06 11:00:00'),
    ]);
    Event::factory()->for($calendar)->create([
        'title' => 'Lunch',
        'starts_at' => CarbonImmutable::parse('2026-08-03 11:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-08-03 12:00:00'),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->has('events', 2)
            ->where('events.0.title', 'Lunch')
            ->where('events.1.title', 'Review')
            ->where('needs_reconnect', false));
});

it('has no events when the calendar is empty', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events', 0));
});

it('flags needs_reconnect when a connected account errored', function () {
    $user = User::factory()->create();
    ConnectedAccount::factory()->for($user)->create(['sync_status' => 'error']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('needs_reconnect', true));
});
