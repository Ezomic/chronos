<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

it('exposes the source app and url on events created from another app', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();

    Event::factory()->for($calendar)->fromEmail()->create([
        'starts_at' => CarbonImmutable::today()->startOfMonth()->addDays(10)->setTime(9, 0),
        'ends_at' => CarbonImmutable::today()->startOfMonth()->addDays(10)->setTime(10, 0),
    ]);

    $this->actingAs($user)
        ->get(route('calendar.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.0.source_app', 'zero')
            ->where('events.0.source_url', fn ($url) => str_starts_with((string) $url, 'https://zero.test/emails/ref/')));
});

it('leaves source fields null for a locally created event', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();

    Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::today()->startOfMonth()->addDays(10)->setTime(9, 0),
        'ends_at' => CarbonImmutable::today()->startOfMonth()->addDays(10)->setTime(10, 0),
    ]);

    $this->actingAs($user)
        ->get(route('calendar.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.0.source_app', null)
            ->where('events.0.source_url', null));
});
