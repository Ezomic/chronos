<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

function zeroUser(): User
{
    $user = User::factory()->create();
    Calendar::factory()->for($user)->default()->create();

    return $user;
}

/**
 * A real bearer token. Sanctum::actingAs installs a mock whose abilities array
 * is unreadable, which would hide the app scoping these endpoints depend on.
 *
 * @param  array<int, string>  $abilities
 */
function actingWithToken(User $user, array $abilities): string
{
    return $user->createToken('test', $abilities)->plainTextToken;
}

function zeroEvent(User $user, string $sourceId = 'MSG-1'): Event
{
    return Event::factory()->for($user->calendars()->firstOrFail())->create([
        'title' => 'Reply to Acme',
        'starts_at' => CarbonImmutable::parse('2026-07-20T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-20T09:30:00Z'),
        'timezone' => 'Europe/Amsterdam',
        'source_app' => 'zero',
        'source_type' => 'email',
        'source_id' => $sourceId,
        'source_url' => 'https://zero.test/emails/ref/'.$sourceId,
    ]);
}

it('lists the calling app own events', function () {
    $user = zeroUser();
    zeroEvent($user, 'MSG-1');
    zeroEvent($user, 'MSG-2');

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $response = $this->getJson('/api/events')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.source.app'))->toBe('zero')
        ->and($response->json('truncated'))->toBeFalse();
});

it('narrows the listing to one source row', function () {
    $user = zeroUser();
    zeroEvent($user, 'MSG-1');
    zeroEvent($user, 'MSG-2');

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $response = $this->getJson('/api/events?source[type]=email&source[id]=MSG-2')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.source.id'))->toBe('MSG-2');
});

it('hides events created by another app', function () {
    $user = zeroUser();
    zeroEvent($user, 'MSG-1');

    $this->withToken(actingWithToken($user, ['events:manage', 'app:tempo']));

    expect($this->getJson('/api/events')->assertOk()->json('data'))->toBe([]);
});

it('updates an event it created', function () {
    $user = zeroUser();
    $event = zeroEvent($user);

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $this->patchJson("/api/events/{$event->id}", [
        'title' => 'Reply to Acme (rescheduled)',
        'starts_at' => '2026-07-21T11:00:00+02:00',
        'ends_at' => '2026-07-21T11:45:00+02:00',
    ])->assertOk()
        ->assertJsonPath('title', 'Reply to Acme (rescheduled)');

    $event->refresh();

    expect($event->title)->toBe('Reply to Acme (rescheduled)')
        ->and($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-07-21 09:00')
        ->and($event->location)->toBe($event->location);
});

it('leaves untouched fields alone on a partial update', function () {
    $user = zeroUser();
    $event = zeroEvent($user);
    $event->forceFill(['location' => 'Room A'])->save();

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $this->patchJson("/api/events/{$event->id}", ['title' => 'Renamed'])->assertOk();

    $event->refresh();

    expect($event->title)->toBe('Renamed')
        ->and($event->location)->toBe('Room A')
        ->and($event->starts_at->utc()->format('H:i'))->toBe('09:00');
});

it('re-arms a spent reminder when the times move', function () {
    $user = zeroUser();
    $event = zeroEvent($user);
    $event->reminders()->create(['minutes_before' => 15, 'sent_at' => now()]);

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $this->patchJson("/api/events/{$event->id}", [
        'starts_at' => '2026-07-21T11:00:00+02:00',
        'ends_at' => '2026-07-21T11:45:00+02:00',
    ])->assertOk();

    expect($event->fresh()->reminders->first()->sent_at)->toBeNull();
});

it('deletes an event it created', function () {
    $user = zeroUser();
    $event = zeroEvent($user);

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $this->deleteJson("/api/events/{$event->id}")->assertNoContent();

    expect(Event::find($event->id))->toBeNull();
});

it('will not touch an event another app created', function () {
    $user = zeroUser();
    $event = zeroEvent($user);

    $this->withToken(actingWithToken($user, ['events:manage', 'app:tempo']));

    $this->patchJson("/api/events/{$event->id}", ['title' => 'Hijacked'])->assertNotFound();
    $this->deleteJson("/api/events/{$event->id}")->assertNotFound();

    expect($event->fresh()->title)->toBe('Reply to Acme');
});

it('will not touch a locally created event with no source', function () {
    $user = zeroUser();
    $event = Event::factory()->for($user->calendars()->firstOrFail())->create();

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $this->patchJson("/api/events/{$event->id}", ['title' => 'Hijacked'])->assertNotFound();
});

it('will not touch another user event', function () {
    $mine = zeroUser();
    $theirs = zeroUser();
    $event = zeroEvent($theirs);

    $this->withToken(actingWithToken($mine, ['events:manage', 'app:zero']));

    $this->patchJson("/api/events/{$event->id}", ['title' => 'Hijacked'])->assertNotFound();
    $this->deleteJson("/api/events/{$event->id}")->assertNotFound();
});

it('will not touch a mirrored event on a read-only calendar', function () {
    $user = zeroUser();
    $mirrored = Calendar::factory()->for($user)->create(['is_writable' => false, 'is_default' => false]);
    $event = Event::factory()->for($mirrored)->create([
        'source_app' => 'zero',
        'source_type' => 'email',
        'source_id' => 'MIRRORED',
        'source_url' => 'https://zero.test/emails/ref/MIRRORED',
    ]);

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $this->deleteJson("/api/events/{$event->id}")->assertNotFound();
});

it('rejects a token without the events:manage ability', function () {
    $user = zeroUser();
    $event = zeroEvent($user);

    $this->withToken(actingWithToken($user, ['events:create', 'app:zero']));

    $this->getJson('/api/events')->assertForbidden();
    $this->patchJson("/api/events/{$event->id}", ['title' => 'Nope'])->assertForbidden();
    $this->deleteJson("/api/events/{$event->id}")->assertForbidden();
});

it('rejects a manage token that does not say which app it speaks for', function () {
    $user = zeroUser();
    $event = zeroEvent($user);

    $this->withToken(actingWithToken($user, ['events:manage']));

    $this->getJson('/api/events')->assertForbidden();
    $this->patchJson("/api/events/{$event->id}", ['title' => 'Nope'])->assertForbidden();
});

it('stops an app-scoped token creating events for another app', function () {
    $this->withToken(actingWithToken(zeroUser(), ['events:create', 'app:zero']));

    $this->postJson('/api/events', [
        'title' => 'Impersonation',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
        'source' => ['app' => 'tempo', 'type' => 'planned-workout', 'id' => '1', 'url' => 'https://tempo.test/p/1'],
    ])->assertForbidden();
});

it('leaves unscoped legacy tokens creating events as before', function () {
    $this->withToken(actingWithToken(zeroUser(), ['events:create']));

    $this->postJson('/api/events', [
        'title' => 'Still fine',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
        'source' => ['app' => 'tempo', 'type' => 'planned-workout', 'id' => '1', 'url' => 'https://tempo.test/p/1'],
    ])->assertCreated();
});

it('rejects an end before the start on update', function () {
    $user = zeroUser();
    $event = zeroEvent($user);

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $this->patchJson("/api/events/{$event->id}", [
        'starts_at' => '2026-07-21T11:00:00Z',
        'ends_at' => '2026-07-21T10:00:00Z',
    ])->assertJsonValidationErrors('ends_at');
});

it('requires both times when moving an event', function () {
    $user = zeroUser();
    $event = zeroEvent($user);

    $this->withToken(actingWithToken($user, ['events:manage', 'app:zero']));

    $this->patchJson("/api/events/{$event->id}", ['starts_at' => '2026-07-21T11:00:00Z'])
        ->assertJsonValidationErrors('ends_at');
});
