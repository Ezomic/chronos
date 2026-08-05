<?php

/**
 * Pins the contract documented in docs/events-api.md. Consumers (zero, tempo)
 * are deployed separately and cannot be updated in lockstep, so a change that
 * would break them has to fail here first.
 */

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;

function contractUser(): User
{
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    Calendar::factory()->for($user)->default()->create();

    return $user;
}

/** @param  array<int, string>  $abilities */
function contractToken(User $user, array $abilities): string
{
    return $user->createToken('contract', $abilities)->plainTextToken;
}

const CONTRACT_EVENT_KEYS = [
    'id',
    'title',
    'description',
    'location',
    'starts_at',
    'ends_at',
    'all_day',
    'timezone',
    'calendar_id',
    'source',
    'url',
];

it('serves the events API under both /api/v1 and the unversioned alias', function (string $path) {
    $this->withToken(contractToken(contractUser(), ['events:create']));

    $this->postJson($path, [
        'title' => 'Kickoff with Acme',
        'starts_at' => '2026-07-20T09:00:00+02:00',
        'ends_at' => '2026-07-20T09:30:00+02:00',
    ])->assertCreated();
})->with(['/api/v1/events', '/api/events']);

it('returns exactly the documented event payload', function () {
    $this->withToken(contractToken(contractUser(), ['events:create']));

    $response = $this->postJson('/api/v1/events', [
        'title' => 'Kickoff with Acme',
        'description' => 'Agenda attached',
        'location' => 'Room A',
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
    ])->assertCreated();

    $body = $response->json();

    // Exact keys, not a subset: an accidental removal has to fail here.
    expect(array_keys($body))->toBe(CONTRACT_EVENT_KEYS)
        ->and(array_keys((array) $body['source']))->toBe(['app', 'type', 'id', 'url'])
        ->and($body['title'])->toBe('Kickoff with Acme')
        ->and($body['description'])->toBe('Agenda attached')
        ->and($body['location'])->toBe('Room A')
        ->and($body['all_day'])->toBeFalse()
        ->and($body['timezone'])->toBe('Europe/Amsterdam')
        // ISO 8601 with an offset, so the instant is unambiguous.
        ->and($body['starts_at'])->toBe('2026-07-20T07:00:00+00:00')
        ->and($body['ends_at'])->toBe('2026-07-20T07:30:00+00:00')
        ->and($body['url'])->toContain('view=day')
        ->and($body['url'])->toContain('date=2026-07-20');
});

it('returns a null source for an event with no origin app', function () {
    $this->withToken(contractToken(contractUser(), ['events:create']));

    $response = $this->postJson('/api/v1/events', [
        'title' => 'Focus block',
        'starts_at' => '2026-07-20T09:00:00+02:00',
        'ends_at' => '2026-07-20T09:30:00+02:00',
    ])->assertCreated();

    expect(array_keys($response->json()))->toBe(CONTRACT_EVENT_KEYS)
        ->and($response->json('source'))->toBeNull();
});

it('answers a repeated create with 200 and the same event', function () {
    $this->withToken(contractToken(contractUser(), ['events:create']));

    $payload = [
        'title' => 'Kickoff',
        'starts_at' => '2026-07-20T09:00:00+02:00',
        'ends_at' => '2026-07-20T09:30:00+02:00',
        'source' => [
            'app' => 'zero', 'type' => 'email', 'id' => 'MSG-1',
            'url' => 'https://zero.test/emails/ref/MSG-1',
        ],
    ];

    $created = $this->postJson('/api/v1/events', $payload)->assertStatus(201);
    $repeat = $this->postJson('/api/v1/events', $payload)->assertStatus(200);

    expect($repeat->json('id'))->toBe($created->json('id'));
});

it('stores an all-day event as an exclusive midnight-UTC span', function () {
    $this->withToken(contractToken(contractUser(), ['events:create']));

    $this->postJson('/api/v1/events', [
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

it('wraps a listing in data and truncated', function () {
    $user = contractUser();
    Event::factory()->for($user->calendars()->firstOrFail())->create([
        'source_app' => 'zero', 'source_type' => 'email', 'source_id' => 'MSG-1',
        'source_url' => 'https://zero.test/emails/ref/MSG-1',
    ]);

    $this->withToken(contractToken($user, ['events:manage', 'app:zero']));

    $response = $this->getJson('/api/v1/events')->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'truncated'])
        ->and(array_keys($response->json('data.0')))->toBe(CONTRACT_EVENT_KEYS)
        ->and($response->json('truncated'))->toBeFalse();
});

/**
 * One token per test: the auth guard caches the user it resolved on the first
 * request, so swapping the Authorization header mid-test does not swap the
 * token the application sees.
 */
function contractEvent(User $user): Event
{
    return Event::factory()->for($user->calendars()->firstOrFail())->create([
        'source_app' => 'zero',
        'source_type' => 'email',
        'source_id' => 'MSG-1',
        'source_url' => 'https://zero.test/emails/ref/MSG-1',
    ]);
}

it('answers an unauthenticated request with 401', function () {
    $this->postJson('/api/v1/events', ['title' => 'x'])->assertStatus(401);
});

it('answers a missing ability with 403', function () {
    $user = contractUser();
    $event = contractEvent($user);

    $this->withToken(contractToken($user, ['events:create', 'app:zero']));

    $this->deleteJson("/api/v1/events/{$event->id}")->assertStatus(403);
});

it('answers a failed validation with 422', function () {
    $this->withToken(contractToken(contractUser(), ['events:create']));

    $this->postJson('/api/v1/events', [
        'title' => 'Backwards',
        'starts_at' => '2026-07-20T10:00:00Z',
        'ends_at' => '2026-07-20T09:00:00Z',
    ])->assertStatus(422);
});

it('answers another app event with 404 rather than 403', function () {
    $user = contractUser();
    $event = contractEvent($user);

    $this->withToken(contractToken($user, ['events:manage', 'app:tempo']));

    $this->deleteJson("/api/v1/events/{$event->id}")->assertStatus(404);
});

it('answers a delete of its own event with 204', function () {
    $user = contractUser();
    $event = contractEvent($user);

    $this->withToken(contractToken($user, ['events:manage', 'app:zero']));

    $this->deleteJson("/api/v1/events/{$event->id}")->assertStatus(204);
});

it('takes its consumer allow-list from config', function () {
    config()->set('chronos.consumers', ['zero' => 'Open in Mail']);

    $this->withToken(contractToken(contractUser(), ['events:create']));

    $payload = fn (string $app): array => [
        'title' => 'Scoped',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
        'source' => ['app' => $app, 'type' => 't', 'id' => '1', 'url' => 'https://example.test/1'],
    ];

    $this->postJson('/api/v1/events', $payload('zero'))->assertCreated();
    $this->postJson('/api/v1/events', $payload('tempo'))->assertJsonValidationErrors('source.app');
});

it('accepts a newly configured consumer without a code change', function () {
    config()->set('chronos.consumers', ['zero' => 'Open in Mail', 'billr' => 'Open in Billr']);

    $this->withToken(contractToken(contractUser(), ['events:create']));

    $this->postJson('/api/v1/events', [
        'title' => 'Invoice due',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
        'source' => ['app' => 'billr', 'type' => 'invoice', 'id' => '9', 'url' => 'https://billr.test/i/9'],
    ])->assertCreated();

    expect(Event::query()->firstOrFail()->source_app)->toBe('billr');
});

it('ships the consumer labels to the frontend', function () {
    config()->set('chronos.consumers', ['zero' => 'Open in Mail']);

    $this->actingAs(contractUser())
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page->where('eventSourceLabels', ['zero' => 'Open in Mail']));
});
