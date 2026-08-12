<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

function pollingUser(): User
{
    $user = User::factory()->create();
    Calendar::factory()->for($user)->default()->create();

    return $user;
}

/** @param  array<int, string>  $abilities */
function pollingToken(User $user, array $abilities): string
{
    return $user->createToken('polling', $abilities)->plainTextToken;
}

function sourcedEvent(User $user, string $sourceId, string $app = 'zero'): Event
{
    return Event::factory()->for($user->calendars()->firstOrFail())->create([
        'title' => 'Reply to '.$sourceId,
        'starts_at' => CarbonImmutable::parse('2026-07-20T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-20T09:30:00Z'),
        'source_app' => $app,
        'source_type' => 'email',
        'source_id' => $sourceId,
        'source_url' => 'https://zero.test/emails/ref/'.$sourceId,
    ]);
}

it('returns only events changed since the given moment', function () {
    $user = pollingUser();

    $old = sourcedEvent($user, 'OLD');
    Event::withTrashed()->whereKey($old->id)->update(['updated_at' => CarbonImmutable::now()->subDays(2)]);

    $fresh = sourcedEvent($user, 'FRESH');

    $this->withToken(pollingToken($user, ['events:manage', 'app:zero']));

    $since = CarbonImmutable::now()->subHour()->toIso8601String();
    $response = $this->getJson('/api/v1/events?changed_since='.urlencode($since))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($fresh->id);
});

it('reports a deleted event as a tombstone rather than hiding it', function () {
    $user = pollingUser();
    $event = sourcedEvent($user, 'MSG-1');

    $this->withToken(pollingToken($user, ['events:manage', 'app:zero']));

    $this->deleteJson("/api/v1/events/{$event->id}")->assertNoContent();

    $since = CarbonImmutable::now()->subHour()->toIso8601String();
    $response = $this->getJson('/api/v1/events?changed_since='.urlencode($since))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($event->id)
        ->and($response->json('data.0.deleted'))->toBeTrue()
        // The last known state comes with it, so a consumer can say what went.
        ->and($response->json('data.0.title'))->toBe('Reply to MSG-1');
});

it('marks live events as not deleted', function () {
    $user = pollingUser();
    sourcedEvent($user, 'MSG-1');

    $this->withToken(pollingToken($user, ['events:manage', 'app:zero']));

    $since = CarbonImmutable::now()->subHour()->toIso8601String();

    expect($this->getJson('/api/v1/events?changed_since='.urlencode($since))->json('data.0.deleted'))
        ->toBeFalse();
});

it('leaves deleted events out of an unfiltered listing', function () {
    $user = pollingUser();
    $event = sourcedEvent($user, 'MSG-1');

    $this->withToken(pollingToken($user, ['events:manage', 'app:zero']));

    $this->deleteJson("/api/v1/events/{$event->id}");

    // Without changed_since this is "what is on the calendar", not a change log.
    expect($this->getJson('/api/v1/events')->assertOk()->json('data'))->toBe([]);
});

it('shows another app nothing, tombstones included', function () {
    $user = pollingUser();
    $event = sourcedEvent($user, 'MSG-1');
    $event->delete();

    $this->withToken(pollingToken($user, ['events:manage', 'app:tempo']));

    $since = CarbonImmutable::now()->subHour()->toIso8601String();

    expect($this->getJson('/api/v1/events?changed_since='.urlencode($since))->assertOk()->json('data'))
        ->toBe([]);
});

it('hands back a changed_through the caller can poll from', function () {
    $user = pollingUser();
    sourcedEvent($user, 'MSG-1');

    $this->withToken(pollingToken($user, ['events:manage', 'app:zero']));

    $since = CarbonImmutable::now()->subHour()->toIso8601String();
    $first = $this->getJson('/api/v1/events?changed_since='.urlencode($since))->assertOk();

    expect($first->json('changed_through'))->not->toBeNull();

    // Nothing has changed since, so a second poll from that point is quiet.
    $second = $this->getJson('/api/v1/events?changed_since='.urlencode($first->json('changed_through')))
        ->assertOk();

    // The boundary is inclusive, so the same row may repeat; what matters is
    // that nothing new appears and the marker does not run ahead.
    expect($second->json('data'))->toHaveCount(1)
        ->and($second->json('changed_through'))->toBe($first->json('changed_through'));
});

it('keeps changed_through at the requested moment when nothing changed', function () {
    $user = pollingUser();

    $this->withToken(pollingToken($user, ['events:manage', 'app:zero']));

    $since = CarbonImmutable::parse('2026-07-01T00:00:00Z')->toIso8601String();
    $response = $this->getJson('/api/v1/events?changed_since='.urlencode($since))->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('changed_through'))->toBe($since);
});

it('reports an event moved in the Chronos UI', function () {
    $user = pollingUser();
    $event = sourcedEvent($user, 'MSG-1');

    $this->withToken(pollingToken($user, ['events:manage', 'app:zero']));

    $since = CarbonImmutable::now()->addSecond()->toIso8601String();

    // Nothing yet.
    expect($this->getJson('/api/v1/events?changed_since='.urlencode($since))->json('data'))->toBe([]);

    $this->travel(2)->seconds();
    $event->forceFill(['starts_at' => CarbonImmutable::parse('2026-07-21T14:00:00Z')])->save();

    $response = $this->getJson('/api/v1/events?changed_since='.urlencode($since))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.starts_at'))->toStartWith('2026-07-21T14:00');
});

it('leaves changed_through null on a listing that is not polling', function () {
    $user = pollingUser();
    sourcedEvent($user, 'MSG-1');

    $this->withToken(pollingToken($user, ['events:manage', 'app:zero']));

    expect($this->getJson('/api/v1/events')->assertOk()->json('changed_through'))->toBeNull();
});

it('rejects a changed_since that is not a date', function () {
    $user = pollingUser();

    $this->withToken(pollingToken($user, ['events:manage', 'app:zero']));

    $this->getJson('/api/v1/events?changed_since=whenever')->assertJsonValidationErrors('changed_since');
});
