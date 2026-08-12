<?php

use App\Actions\SyncConnectedAccountAction;
use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

function ownedEvent(User $user): Event
{
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    return Event::factory()->for($calendar)->create([
        'title' => 'Dentist',
        'starts_at' => CarbonImmutable::now()->addDays(2)->startOfHour(),
        'ends_at' => CarbonImmutable::now()->addDays(2)->startOfHour()->addHour(),
    ]);
}

it('soft-deletes an event and offers a way back', function () {
    $user = User::factory()->create();
    $event = ownedEvent($user);

    $this->actingAs($user)->delete(route('events.destroy', $event))->assertRedirect();

    expect(Event::find($event->id))->toBeNull()
        ->and(Event::withTrashed()->find($event->id))->not->toBeNull()
        ->and(Event::withTrashed()->find($event->id)->deleted_at)->not->toBeNull();
});

it('restores a deleted event', function () {
    $user = User::factory()->create();
    $event = ownedEvent($user);

    $this->actingAs($user)->delete(route('events.destroy', $event));
    $this->actingAs($user)->post(route('events.restore', $event))->assertRedirect();

    $restored = Event::find($event->id);

    expect($restored)->not->toBeNull()
        ->and($restored->title)->toBe('Dentist')
        ->and($restored->deleted_at)->toBeNull();
});

it('hides a deleted event from the calendar and brings it back on restore', function () {
    $user = User::factory()->create();
    $event = Event::factory()
        ->for(Calendar::factory()->for($user)->create(['is_writable' => true]))
        ->create([
            'starts_at' => CarbonImmutable::parse('2026-07-15T09:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-07-15T10:00:00Z'),
        ]);

    $grid = fn () => $this->actingAs($user)
        ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-07-15']));

    $grid()->assertInertia(fn (AssertableInertia $page) => $page->has('events', 1));

    $this->actingAs($user)->delete(route('events.destroy', $event));
    $grid()->assertInertia(fn (AssertableInertia $page) => $page->has('events', 0));

    $this->actingAs($user)->post(route('events.restore', $event));
    $grid()->assertInertia(fn (AssertableInertia $page) => $page->has('events', 1));
});

it('will not restore another user event', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $event = ownedEvent($theirs);

    $this->actingAs($theirs)->delete(route('events.destroy', $event));

    $this->actingAs($mine)->post(route('events.restore', $event))->assertForbidden();

    expect(Event::find($event->id))->toBeNull();
});

it('takes a series overrides down and brings them back together', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    $series = Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;UNTIL=20260727T235959Z',
    ]);

    $override = Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-13T14:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-13T14:30:00Z'),
        'timezone' => 'UTC',
        'overrides_event_id' => $series->id,
        'overrides_starts_at' => CarbonImmutable::parse('2026-07-13T09:00:00Z'),
    ]);

    $this->actingAs($user)->delete(route('events.destroy', $series));

    // A soft delete does not fire the database cascade, so this is the case
    // that would otherwise leave the override rendering on its own.
    expect(Event::find($series->id))->toBeNull()
        ->and(Event::find($override->id))->toBeNull();

    $this->actingAs($user)->post(route('events.restore', $series));

    expect(Event::find($series->id))->not->toBeNull()
        ->and(Event::find($override->id))->not->toBeNull();
});

it('leaves an individually deleted override deleted when the series is restored', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);

    $series = Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;UNTIL=20260727T235959Z',
    ]);

    $override = Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-13T14:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-13T14:30:00Z'),
        'timezone' => 'UTC',
        'overrides_event_id' => $series->id,
        'overrides_starts_at' => CarbonImmutable::parse('2026-07-13T09:00:00Z'),
    ]);

    $this->actingAs($user)->delete(route('events.destroy', $override));
    $this->actingAs($user)->delete(route('events.destroy', $series));
    $this->actingAs($user)->post(route('events.restore', $series));

    expect(Event::find($series->id))->not->toBeNull()
        ->and(Event::find($override->id))->toBeNull();
});

it('hard-deletes mirrored events pruned by the sync', function () {
    $account = ConnectedAccount::factory()->create([
        'provider' => ConnectedAccount::PROVIDER_GOOGLE,
        'oauth_access_token' => 'valid',
        'oauth_expires_at' => now()->addHour(),
    ]);

    $day = CarbonImmutable::now()->addDays(5);

    $instance = fn (string $id): array => [
        'id' => $id,
        'summary' => 'Mirrored',
        'start' => ['dateTime' => $day->format('Y-m-d').'T09:00:00+00:00', 'timeZone' => 'UTC'],
        'end' => ['dateTime' => $day->format('Y-m-d').'T09:30:00+00:00'],
    ];

    Http::fake([
        '*/users/me/calendarList' => Http::response(['items' => [
            ['id' => 'primary', 'summary' => 'Work', 'timeZone' => 'UTC'],
        ]]),
        '*/events*' => Http::sequence()
            ->push(['items' => [$instance('keep'), $instance('vanishes')]])
            ->push(['items' => [$instance('keep')]]),
    ]);

    $sync = fn () => app(SyncConnectedAccountAction::class)->handle($account->fresh());

    $sync();
    $sync();

    // Soft-deleting a mirror would leave a row that can never be cleanly
    // re-mirrored, so the prune has to be permanent.
    expect(Event::withTrashed()->where('external_id', 'vanishes')->count())->toBe(0)
        ->and(Event::where('external_id', 'keep')->count())->toBe(1);
});

it('lets a consuming app create again after the user deleted its event', function () {
    $user = User::factory()->create();
    Calendar::factory()->for($user)->default()->create();
    Sanctum::actingAs($user, ['events:create']);

    $payload = [
        'title' => 'Kickoff',
        'starts_at' => '2026-07-20T09:00:00Z',
        'ends_at' => '2026-07-20T10:00:00Z',
        'source' => [
            'app' => 'zero', 'type' => 'email', 'id' => 'MSG-1',
            'url' => 'https://zero.test/emails/ref/MSG-1',
        ],
    ];

    $first = $this->postJson('/api/v1/events', $payload)->assertCreated();

    $this->actingAs($user)->delete(route('events.destroy', $first->json('id')));

    // The unique index is scoped to live rows, so the deleted one does not
    // block this, and the user's deletion is not silently undone either.
    $second = $this->postJson('/api/v1/events', $payload)->assertCreated();

    expect($second->json('id'))->not->toBe($first->json('id'))
        ->and(Event::query()->count())->toBe(1);
});

it('purges events deleted longer ago than the grace period', function () {
    $user = User::factory()->create();
    $recent = ownedEvent($user);
    $old = ownedEvent($user);

    $this->actingAs($user)->delete(route('events.destroy', $recent));
    $this->actingAs($user)->delete(route('events.destroy', $old));

    Event::withTrashed()->whereKey($old->id)->update([
        'deleted_at' => CarbonImmutable::now()->subDays(45),
    ]);

    $this->artisan('chronos:purge-deleted-events')->assertSuccessful();

    expect(Event::withTrashed()->find($old->id))->toBeNull()
        ->and(Event::withTrashed()->find($recent->id))->not->toBeNull();
});
