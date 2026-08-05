<?php

use App\Actions\SyncConnectedAccountAction;
use App\Exceptions\UnsafeFeedUrlException;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\Calendar\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Bind what a host resolves to, so these assert the guard rather than whatever
 * DNS the machine running them happens to have.
 *
 * @param  array<int, string>  $addresses
 */
function resolvingTo(array $addresses): void
{
    app()->instance(HostResolver::class, new class($addresses) extends HostResolver
    {
        /** @param array<int, string> $addresses */
        public function __construct(private array $addresses) {}

        public function resolve(string $host): array
        {
            // An IP literal is itself, exactly as the real resolver treats it.
            return filter_var($host, FILTER_VALIDATE_IP) !== false
                ? [$host]
                : $this->addresses;
        }
    });
}

function subscribe(string $url): TestResponse
{
    return test()->actingAs(User::factory()->create())
        ->post(route('subscriptions.store'), ['url' => $url]);
}

it('refuses a feed that resolves to loopback', function () {
    resolvingTo(['127.0.0.1']);

    subscribe('https://sneaky.example/calendar.ics')->assertSessionHasErrors('url');

    expect(ConnectedAccount::query()->count())->toBe(0);
});

it('refuses a feed that resolves to a private range', function (string $address) {
    resolvingTo([$address]);

    subscribe('https://sneaky.example/calendar.ics')->assertSessionHasErrors('url');
})->with(['10.0.0.5', '172.16.4.2', '192.168.1.10']);

it('refuses the cloud metadata address', function () {
    resolvingTo(['169.254.169.254']);

    subscribe('https://sneaky.example/latest/meta-data/')->assertSessionHasErrors('url');
});

it('refuses IPv6 loopback', function () {
    resolvingTo(['::1']);

    subscribe('https://sneaky.example/calendar.ics')->assertSessionHasErrors('url');
});

it('refuses a host that resolves to a public and a private address', function () {
    // A DNS rebinding answer only has to include one address we would connect
    // to; every address has to be safe, not just the first.
    resolvingTo(['93.184.216.34', '127.0.0.1']);

    subscribe('https://sneaky.example/calendar.ics')->assertSessionHasErrors('url');
});

it('refuses a literal private address without any lookup', function () {
    subscribe('http://192.168.1.10:8080/calendar.ics')->assertSessionHasErrors('url');
});

it('accepts a normal public feed', function () {
    resolvingTo(['93.184.216.34']);

    Http::fake(['*' => Http::response(implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//EN', 'X-WR-CALNAME:Fixtures', 'END:VCALENDAR',
    ])."\r\n")]);

    subscribe('https://feeds.example/calendar.ics')->assertSessionHasNoErrors();

    expect(ConnectedAccount::query()->where('provider', 'ics')->count())->toBe(1);
});

it('re-checks the address on every sync, not just at subscribe time', function () {
    resolvingTo(['93.184.216.34']);

    Http::fake(['*' => Http::response(implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//EN', 'X-WR-CALNAME:Fixtures', 'END:VCALENDAR',
    ])."\r\n")]);

    subscribe('https://feeds.example/calendar.ics')->assertSessionHasNoErrors();

    $account = ConnectedAccount::query()->where('provider', 'ics')->firstOrFail();

    // The feed's DNS now answers with an internal address.
    resolvingTo(['10.0.0.5']);

    expect(fn () => app(SyncConnectedAccountAction::class)->handle($account))
        ->toThrow(UnsafeFeedUrlException::class);
});

it('re-checks each redirect hop against the same rule', function () {
    resolvingTo(['93.184.216.34']);

    Http::fake([
        'feeds.example/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '*' => Http::response('should never be reached'),
    ]);

    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => ConnectedAccount::PROVIDER_ICS,
        'email_address' => null,
        'feed_url' => 'https://feeds.example/calendar.ics',
        'feed_url_hash' => hash('sha256', 'https://feeds.example/calendar.ics'),
        'oauth_access_token' => null,
        'oauth_refresh_token' => null,
        'oauth_expires_at' => null,
    ]);

    // The redirect target is an IP literal, so it is checked without a lookup
    // even though the resolver would call it public.
    expect(fn () => app(SyncConnectedAccountAction::class)->handle($account))
        ->toThrow(UnsafeFeedUrlException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'meta-data'));
});

it('refuses a feed larger than the size cap', function () {
    resolvingTo(['93.184.216.34']);

    // Well past the 5 MB cap, and never a valid iCalendar document.
    Http::fake(['*' => Http::response(str_repeat('X', 6 * 1024 * 1024))]);

    subscribe('https://feeds.example/huge.ics')->assertSessionHasErrors('url');

    expect(ConnectedAccount::query()->count())->toBe(0);
});
