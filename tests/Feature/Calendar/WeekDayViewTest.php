<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

it('scopes the week view to the anchor week', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    // Anchor Wed 2026-07-15 -> week is Mon 07-13 .. Sun 07-19.
    Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-15T10:00:00Z'),
    ]);
    Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-25T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-25T10:00:00Z'),
    ]);

    $this->actingAs($user)
        ->get(route('calendar.index', ['view' => 'week', 'date' => '2026-07-15']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'week')
            ->has('events', 1));
});

it('scopes the day view to the anchor day', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-15T10:00:00Z'),
    ]);
    // Well outside the day window's ±1-day timezone padding.
    Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-20T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-20T10:00:00Z'),
    ]);

    $this->actingAs($user)
        ->get(route('calendar.index', ['view' => 'day', 'date' => '2026-07-15']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'day')
            ->has('events', 1));
});

it('falls back to the month view for an unknown view', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('calendar.index', ['view' => 'decade']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('view', 'month'));
});
