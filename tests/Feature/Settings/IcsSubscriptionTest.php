<?php

use App\Jobs\SyncConnectedAccountJob;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function validIcsBody(): string
{
    return implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Test//EN',
        'X-WR-CALNAME:FC Twente',
        'END:VCALENDAR',
    ])."\r\n";
}

it('subscribes to an ICS feed and dispatches an immediate sync', function () {
    Queue::fake();
    Http::fake(['feeds.test/*' => Http::response(validIcsBody())]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('subscriptions.store'), [
            'url' => 'https://feeds.test/twente.ics',
            'timezone' => 'Europe/Amsterdam',
        ])
        ->assertRedirect();

    $account = ConnectedAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', ConnectedAccount::PROVIDER_ICS)
        ->firstOrFail();

    expect($account->feed_url)->toBe('https://feeds.test/twente.ics')
        ->and($account->feed_url_hash)->toBe(hash('sha256', 'https://feeds.test/twente.ics'))
        ->and($account->display_name)->toBe('FC Twente')
        ->and($account->timezone)->toBe('Europe/Amsterdam')
        ->and($account->email_address)->toBeNull();

    Queue::assertPushed(SyncConnectedAccountJob::class);
});

it('normalizes a webcal:// URL to https://', function () {
    Queue::fake();
    Http::fake(['feeds.test/*' => Http::response(validIcsBody())]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('subscriptions.store'), ['url' => 'webcal://feeds.test/twente.ics'])
        ->assertRedirect();

    expect(ConnectedAccount::query()->where('user_id', $user->id)->value('feed_url'))
        ->toBe('https://feeds.test/twente.ics');
});

it('uses a provided name over the feed name', function () {
    Queue::fake();
    Http::fake(['feeds.test/*' => Http::response(validIcsBody())]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('subscriptions.store'), [
            'url' => 'https://feeds.test/twente.ics',
            'name' => 'Twente matches',
        ])
        ->assertRedirect();

    expect(ConnectedAccount::query()->where('user_id', $user->id)->value('display_name'))
        ->toBe('Twente matches');
});

it('rejects a URL that does not serve an iCalendar feed', function () {
    Queue::fake();
    Http::fake(['feeds.test/*' => Http::response('<html>not a calendar</html>')]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('subscriptions.store'), ['url' => 'https://feeds.test/nope'])
        ->assertSessionHasErrors('url');

    expect(ConnectedAccount::query()->where('user_id', $user->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects an unreachable feed', function () {
    Queue::fake();
    Http::fake(['feeds.test/*' => Http::response('down', 500)]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('subscriptions.store'), ['url' => 'https://feeds.test/down.ics'])
        ->assertSessionHasErrors('url');

    expect(ConnectedAccount::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('rejects a duplicate subscription to the same feed', function () {
    Queue::fake();
    Http::fake(['feeds.test/*' => Http::response(validIcsBody())]);

    $user = User::factory()->create();
    $url = 'https://feeds.test/twente.ics';

    ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => ConnectedAccount::PROVIDER_ICS,
        'email_address' => null,
        'feed_url' => $url,
        'feed_url_hash' => hash('sha256', $url),
    ]);

    $this->actingAs($user)
        ->post(route('subscriptions.store'), ['url' => $url])
        ->assertSessionHasErrors('url');

    expect(ConnectedAccount::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('requires a URL', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('subscriptions.store'), ['url' => ''])
        ->assertSessionHasErrors('url');
});

it('re-dispatches a sync for a feed on demand', function () {
    Queue::fake();

    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => ConnectedAccount::PROVIDER_ICS,
        'email_address' => null,
        'feed_url' => 'https://feeds.test/twente.ics',
        'feed_url_hash' => hash('sha256', 'https://feeds.test/twente.ics'),
    ]);

    $this->actingAs($user)
        ->post(route('connected-accounts.resync', $account))
        ->assertRedirect();

    Queue::assertPushed(SyncConnectedAccountJob::class);
});
