<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

function calendarFor(User $user): Calendar
{
    return Calendar::factory()->for($user)->create(['is_writable' => true]);
}

it('renders the month view for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('calendar/Index')
            ->where('view', 'month')
            ->where('date', CarbonImmutable::today()->toDateString())
            ->has('events'));
});

it('requires authentication', function () {
    $this->get(route('calendar.index'))->assertRedirect(route('login'));
});

it('returns events inside the visible window but not outside it', function () {
    $user = User::factory()->create();
    $calendar = calendarFor($user);

    $inside = Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::today()->startOfMonth()->addDays(10)->setTime(9, 0),
        'ends_at' => CarbonImmutable::today()->startOfMonth()->addDays(10)->setTime(10, 0),
    ]);

    Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::today()->addMonths(3),
        'ends_at' => CarbonImmutable::today()->addMonths(3)->addHour(),
    ]);

    $this->actingAs($user)
        ->get(route('calendar.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 1)
            ->where('events.0.id', $inside->id));
});

it('only returns the user\'s own events on visible calendars', function () {
    $user = User::factory()->create();
    $anchor = CarbonImmutable::today()->startOfMonth()->addDays(10);

    $visible = Calendar::factory()->for($user)->create(['is_visible' => true]);
    Event::factory()->for($visible)->create(['starts_at' => $anchor->setTime(9, 0), 'ends_at' => $anchor->setTime(10, 0)]);

    $hidden = Calendar::factory()->for($user)->create(['is_visible' => false]);
    Event::factory()->for($hidden)->create(['starts_at' => $anchor->setTime(11, 0), 'ends_at' => $anchor->setTime(12, 0)]);

    $otherUsersCalendar = Calendar::factory()->create();
    Event::factory()->for($otherUsersCalendar)->create(['starts_at' => $anchor->setTime(13, 0), 'ends_at' => $anchor->setTime(14, 0)]);

    $this->actingAs($user)
        ->get(route('calendar.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('events', 1));
});

it('honours the date query parameter and scopes events to that month', function () {
    $user = User::factory()->create();
    $calendar = calendarFor($user);

    Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-01-15T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-01-15T10:00:00Z'),
    ]);

    $this->actingAs($user)
        ->get(route('calendar.index', ['date' => '2026-01-10']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('date', '2026-01-10')
            ->has('events', 1));
});

it('falls back to today when the date parameter is malformed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('calendar.index', ['date' => 'not-a-date']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('date', CarbonImmutable::today()->toDateString()));
});
