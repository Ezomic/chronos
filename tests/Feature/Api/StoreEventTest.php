<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function userWithDefaultCalendar(): User
{
    $user = User::factory()->create();
    Calendar::factory()->for($user)->default()->create();

    return $user;
}

it('creates an event from a valid request with a source link', function () {
    $user = userWithDefaultCalendar();
    Sanctum::actingAs($user, ['events:create']);

    $response = $this->postJson('/api/events', [
        'title' => 'Kickoff with Acme',
        'starts_at' => '2026-07-20T09:00:00+02:00',
        'ends_at' => '2026-07-20T09:30:00+02:00',
        'all_day' => false,
        'timezone' => 'Europe/Amsterdam',
        'source' => [
            'app' => 'zero',
            'type' => 'email',
            'id' => '01JZ8XABCDEF0123456789ABCD',
            'url' => 'https://zero.test/emails/ref/01JZ8XABCDEF0123456789ABCD',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['id', 'title', 'starts_at', 'ends_at', 'url']);

    $event = Event::query()->firstOrFail();
    expect($event->title)->toBe('Kickoff with Acme')
        ->and($event->source_app)->toBe('zero')
        ->and($event->source_id)->toBe('01JZ8XABCDEF0123456789ABCD')
        ->and($event->starts_at->utc()->format('H:i'))->toBe('07:00');
});

it('rejects an unauthenticated request', function () {
    $this->postJson('/api/events', ['title' => 'x'])->assertUnauthorized();
});

it('rejects a token without the events:create ability', function () {
    $user = userWithDefaultCalendar();
    Sanctum::actingAs($user, ['something-else']);

    $this->postJson('/api/events', [
        'title' => 'Nope',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
    ])->assertForbidden();
});

it('validates that the end is after the start', function () {
    Sanctum::actingAs(userWithDefaultCalendar(), ['events:create']);

    $this->postJson('/api/events', [
        'title' => 'Backwards',
        'starts_at' => '2026-07-20T10:00:00Z',
        'ends_at' => '2026-07-20T09:00:00Z',
    ])->assertJsonValidationErrors('ends_at');
});

it('rejects an unknown source app', function () {
    Sanctum::actingAs(userWithDefaultCalendar(), ['events:create']);

    $this->postJson('/api/events', [
        'title' => 'Sketchy',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
        'source' => [
            'app' => 'evil',
            'type' => 'phish',
            'id' => 'x',
            'url' => 'https://evil.example/x',
        ],
    ])->assertJsonValidationErrors('source.app');
});

it('stores an all-day event as an exclusive midnight-UTC span', function () {
    Sanctum::actingAs(userWithDefaultCalendar(), ['events:create']);

    $this->postJson('/api/events', [
        'title' => 'Conference',
        'starts_at' => '2026-07-20',
        'ends_at' => '2026-07-20',
        'all_day' => true,
    ])->assertCreated();

    $event = Event::query()->firstOrFail();
    expect($event->all_day)->toBeTrue()
        ->and($event->timezone)->toBe('UTC')
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe('2026-07-20 00:00')
        ->and($event->ends_at->format('Y-m-d H:i'))->toBe('2026-07-21 00:00');
});

it('returns 422 when the user has no writable calendar', function () {
    $user = User::factory()->create();
    // Users are provisioned with a default calendar; remove it to exercise the guard.
    $user->calendars()->delete();
    Sanctum::actingAs($user, ['events:create']);

    $this->postJson('/api/events', [
        'title' => 'Homeless',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
    ])->assertStatus(422);
});

it('rate limits after 60 requests a minute', function () {
    Sanctum::actingAs(userWithDefaultCalendar(), ['events:create']);

    $payload = [
        'title' => 'Spam',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
    ];

    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/events', $payload)->assertCreated();
    }

    $this->postJson('/api/events', $payload)->assertStatus(429);
});
