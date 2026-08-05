<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
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

it('accepts a tempo source app', function () {
    Sanctum::actingAs(userWithDefaultCalendar(), ['events:create']);

    $this->postJson('/api/events', [
        'title' => 'Run: Easy 40 (40 min)',
        'starts_at' => '2026-07-25',
        'ends_at' => '2026-07-25',
        'all_day' => true,
        'source' => [
            'app' => 'tempo',
            'type' => 'planned-workout',
            'id' => '42',
            'url' => 'https://tempo.test/plan',
        ],
    ])->assertCreated();

    expect(Event::query()->firstOrFail()->source_app)->toBe('tempo');
});

it('returns the existing event instead of creating a duplicate for the same source', function () {
    Sanctum::actingAs(userWithDefaultCalendar(), ['events:create']);

    $payload = [
        'title' => 'Kickoff with Acme',
        'starts_at' => '2026-07-20T09:00:00+02:00',
        'ends_at' => '2026-07-20T09:30:00+02:00',
        'source' => [
            'app' => 'zero',
            'type' => 'email',
            'id' => '01JZ8XABCDEF0123456789ABCD',
            'url' => 'https://zero.test/emails/ref/01JZ8XABCDEF0123456789ABCD',
        ],
    ];

    $first = $this->postJson('/api/events', $payload)->assertCreated();
    $second = $this->postJson('/api/events', $payload)->assertOk();

    expect(Event::query()->count())->toBe(1)
        ->and($second->json('id'))->toBe($first->json('id'));
});

it('still creates a second event for a different source id', function () {
    Sanctum::actingAs(userWithDefaultCalendar(), ['events:create']);

    $payload = fn (string $id): array => [
        'title' => 'Kickoff',
        'starts_at' => '2026-07-20T09:00:00+02:00',
        'ends_at' => '2026-07-20T09:30:00+02:00',
        'source' => ['app' => 'zero', 'type' => 'email', 'id' => $id, 'url' => 'https://zero.test/e/'.$id],
    ];

    $this->postJson('/api/events', $payload('one'))->assertCreated();
    $this->postJson('/api/events', $payload('two'))->assertCreated();

    expect(Event::query()->count())->toBe(2);
});

it('does not deduplicate events posted without a source', function () {
    Sanctum::actingAs(userWithDefaultCalendar(), ['events:create']);

    $payload = [
        'title' => 'Focus block',
        'starts_at' => '2026-07-20T09:00:00+02:00',
        'ends_at' => '2026-07-20T09:30:00+02:00',
    ];

    $this->postJson('/api/events', $payload)->assertCreated();
    $this->postJson('/api/events', $payload)->assertCreated();

    expect(Event::query()->count())->toBe(2);
});

it('recognises a source event the user moved to another calendar', function () {
    $user = userWithDefaultCalendar();
    Sanctum::actingAs($user, ['events:create']);

    $payload = [
        'title' => 'Standup',
        'starts_at' => '2026-07-20T09:00:00+02:00',
        'ends_at' => '2026-07-20T09:15:00+02:00',
        'source' => ['app' => 'tempo', 'type' => 'planned-workout', 'id' => '7', 'url' => 'https://tempo.test/plan'],
    ];

    $created = $this->postJson('/api/events', $payload)->assertCreated();

    $other = Calendar::factory()->for($user)->create(['is_writable' => true, 'is_default' => false]);
    Event::query()->findOrFail($created->json('id'))->forceFill(['calendar_id' => $other->id])->save();

    $this->postJson('/api/events', $payload)->assertOk();

    expect(Event::query()->count())->toBe(1);
});

it('backs the dedupe with a unique constraint so a race cannot slip through', function () {
    $calendar = Calendar::factory()->create();

    $source = [
        'source_app' => 'zero',
        'source_type' => 'email',
        'source_id' => 'DUPLICATE',
        'source_url' => 'https://zero.test/emails/ref/DUPLICATE',
    ];

    Event::factory()->for($calendar)->create($source);

    expect(fn () => Event::factory()->for($calendar)->create($source))
        ->toThrow(QueryException::class);
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

it('routes an event to a calendar named in the request', function () {
    $user = userWithDefaultCalendar();
    $training = Calendar::factory()->for($user)->create(['name' => 'Training']);
    Sanctum::actingAs($user, ['events:create']);

    $this->postJson('/api/events', [
        'calendar' => 'Training',
        'title' => 'Easy 40',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T09:40:00Z',
    ])->assertCreated();

    expect(Event::query()->firstOrFail()->calendar_id)->toBe($training->id);
});

it('routes an event to a calendar named by id', function () {
    $user = userWithDefaultCalendar();
    $training = Calendar::factory()->for($user)->create(['name' => 'Training']);
    Sanctum::actingAs($user, ['events:create']);

    $this->postJson('/api/events', [
        'calendar' => $training->id,
        'title' => 'Easy 40',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T09:40:00Z',
    ])->assertCreated();

    expect(Event::query()->firstOrFail()->calendar_id)->toBe($training->id);
});

it('rejects a calendar that does not exist', function () {
    Sanctum::actingAs(userWithDefaultCalendar(), ['events:create']);

    $this->postJson('/api/events', [
        'calendar' => 'Nowhere',
        'title' => 'Lost',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
    ])->assertJsonValidationErrors('calendar');

    expect(Event::query()->count())->toBe(0);
});

it('rejects a mirrored calendar as a target', function () {
    $user = userWithDefaultCalendar();
    $mirrored = Calendar::factory()->for($user)->mirrored()->create(['name' => 'Work (Google)']);
    Sanctum::actingAs($user, ['events:create']);

    $this->postJson('/api/events', [
        'calendar' => $mirrored->id,
        'title' => 'Read only',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
    ])->assertJsonValidationErrors('calendar');
});

it('rejects another user calendar as a target', function () {
    $user = userWithDefaultCalendar();
    $theirs = Calendar::factory()->create(['name' => 'Theirs']);
    Sanctum::actingAs($user, ['events:create']);

    $this->postJson('/api/events', [
        'calendar' => $theirs->id,
        'title' => 'Not mine',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
    ])->assertJsonValidationErrors('calendar');
});

it('falls back deterministically when no calendar is flagged default', function () {
    $user = User::factory()->create();
    $user->calendars()->delete();

    // Created out of alphabetical order, none of them default.
    Calendar::factory()->for($user)->create(['name' => 'Work']);
    $admin = Calendar::factory()->for($user)->create(['name' => 'Admin']);
    Calendar::factory()->for($user)->create(['name' => 'Training']);

    Sanctum::actingAs($user, ['events:create']);

    foreach (['one', 'two'] as $index => $title) {
        $this->postJson('/api/events', [
            'title' => $title,
            'starts_at' => '2026-07-2'.$index.'T09:00:00Z',
            'ends_at' => '2026-07-2'.$index.'T10:00:00Z',
        ])->assertCreated();
    }

    expect(Event::query()->pluck('calendar_id')->unique()->all())->toBe([$admin->id]);
});

it('still prefers the default calendar over the alphabetical first', function () {
    $user = userWithDefaultCalendar();
    Calendar::factory()->for($user)->create(['name' => 'Admin']);

    Sanctum::actingAs($user, ['events:create']);

    $this->postJson('/api/events', [
        'title' => 'Goes to Personal',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
    ])->assertCreated();

    $default = $user->calendars()->where('is_default', true)->firstOrFail();
    expect(Event::query()->firstOrFail()->calendar_id)->toBe($default->id);
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
