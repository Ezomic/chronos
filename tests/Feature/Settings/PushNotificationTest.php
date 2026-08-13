<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\EventReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/** @param array<string, mixed> $overrides */
function subscriptionPayload(array $overrides = []): array
{
    return [
        'endpoint' => 'https://push.example/endpoint/abc123',
        'public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa40HcCWLEd1PqAF7c',
        'auth_token' => 'kZTCk82psaREL1HXbxpzUw',
        'device_label' => 'Chrome on Mac',
        ...$overrides,
    ];
}

it('stores a push subscription for the current user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('push-subscriptions.store'), subscriptionPayload())
        ->assertRedirect();

    $subscription = PushSubscription::query()->firstOrFail();

    expect($subscription->user_id)->toBe($user->id)
        ->and($subscription->device_label)->toBe('Chrome on Mac')
        ->and($subscription->endpoint_hash)->toBe(hash('sha256', 'https://push.example/endpoint/abc123'));
});

it('updates rather than duplicates when a browser re-subscribes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('push-subscriptions.store'), subscriptionPayload());
    $this->actingAs($user)->post(route('push-subscriptions.store'), subscriptionPayload([
        'auth_token' => 'a-new-token',
    ]));

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->firstOrFail()->auth_token)->toBe('a-new-token');
});

it('rejects an endpoint that is not https', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('push-subscriptions.store'), subscriptionPayload([
            'endpoint' => 'http://push.example/endpoint/abc123',
        ]))
        ->assertSessionHasErrors('endpoint');
});

it('removes a device', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('push-subscriptions.store'), subscriptionPayload());

    $subscription = PushSubscription::query()->firstOrFail();

    $this->actingAs($user)
        ->delete(route('push-subscriptions.destroy', $subscription))
        ->assertRedirect();

    expect(PushSubscription::query()->count())->toBe(0);
});

it('will not remove another user device', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $subscription = PushSubscription::query()->create([
        'user_id' => $theirs->id,
        'endpoint' => 'https://push.example/theirs',
        'endpoint_hash' => hash('sha256', 'https://push.example/theirs'),
        'public_key' => 'key',
        'auth_token' => 'token',
    ]);

    $this->actingAs($mine)
        ->delete(route('push-subscriptions.destroy', $subscription))
        ->assertForbidden();

    expect(PushSubscription::query()->count())->toBe(1);
});

it('lists devices on the profile page', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('push-subscriptions.store'), subscriptionPayload());

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->has('pushDevices', 1)
            ->where('pushDevices.0.label', 'Chrome on Mac'));
});

it('never exposes the endpoint or keys to the page', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('push-subscriptions.store'), subscriptionPayload());

    // The endpoint is what lets anyone push to that device, so it stays server
    // side even on the user's own settings page.
    $response = $this->actingAs($user)->get(route('profile.edit'));
    $devices = $response->viewData('page')['props']['pushDevices'];

    expect(array_keys($devices[0]))->toBe(['id', 'label', 'added_at_diff', 'last_used_at_diff']);
});

it('sends a reminder by mail when no device is listening', function () {
    Notification::fake();

    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $event = Event::factory()->for($calendar)->create([
        'starts_at' => now()->addMinutes(10),
        'ends_at' => now()->addMinutes(40),
    ]);
    $event->reminders()->create(['minutes_before' => 15]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    Notification::assertSentTo(
        $user,
        EventReminderNotification::class,
        fn (EventReminderNotification $notification, array $channels) => $channels === ['mail'],
    );
});

it('sends a reminder by push when a device is listening', function () {
    Notification::fake();

    $user = User::factory()->create();

    PushSubscription::query()->create([
        'user_id' => $user->id,
        'endpoint' => 'https://push.example/endpoint/abc123',
        'endpoint_hash' => hash('sha256', 'https://push.example/endpoint/abc123'),
        'public_key' => 'key',
        'auth_token' => 'token',
    ]);

    $calendar = Calendar::factory()->for($user)->create();
    $event = Event::factory()->for($calendar)->create([
        'starts_at' => now()->addMinutes(10),
        'ends_at' => now()->addMinutes(40),
    ]);
    $event->reminders()->create(['minutes_before' => 15]);

    $this->artisan('chronos:send-reminders')->assertSuccessful();

    // Push instead of mail, not as well as: one reminder should arrive once.
    Notification::assertSentTo(
        $user,
        EventReminderNotification::class,
        fn (EventReminderNotification $notification, array $channels) => $channels === [WebPushChannel::class],
    );
});

it('builds a push payload that opens the event day', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $event = Event::factory()->for($calendar)->create([
        'title' => 'Dentist',
        'starts_at' => CarbonImmutable::parse('2026-07-20T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-20T09:30:00Z'),
        'timezone' => 'UTC',
        'all_day' => false,
    ]);

    $payload = (new EventReminderNotification($event))->toWebPush($user);

    expect($payload['title'])->toBe('Dentist')
        ->and($payload['body'])->toContain('09:00')
        ->and($payload['url'])->toContain('date=2026-07-20')
        ->and($payload['tag'])->toBe('event-'.$event->id.'-202607200900');
});

it('removes a subscription with the user', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('push-subscriptions.store'), subscriptionPayload());

    $user->delete();

    expect(PushSubscription::query()->count())->toBe(0);
});
