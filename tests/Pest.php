<?php

use App\Actions\EnsureBookingTypeAction;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Services\Calendar\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Feed hosts resolve to a public address by default. Without this, DNS
        // decides the outcome: a Herd laptop answers 127.0.0.1 for .test and CI
        // answers nothing, so the feed guard would behave differently in each.
        // The tests that care about the guard bind their own resolver.
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                // An IP literal is itself, exactly as the real resolver treats it.
                return filter_var($host, FILTER_VALIDATE_IP) !== false
                    ? [$host]
                    : ['93.184.216.34'];
            }
        });
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** The calendar owner the public page books against: the first user. */
function bookingOwner(): User
{
    return User::factory()->create(['name' => 'Owner', 'email' => 'owner@example.test']);
}

function introCall(User $owner): BookingType
{
    return app(EnsureBookingTypeAction::class)->handle($owner);
}

/** Monday 2 Nov 2026, 08:00 in Amsterdam: a quiet start to a normal week. */
function atMondayMorning(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Amsterdam'));
}

/** @return Collection<int, CarbonImmutable> */
function bookableSlots(string $query = ''): Collection
{
    $slots = [];

    test()->get('/book'.$query)->assertOk()->assertInertia(function ($page) use (&$slots) {
        $slots = $page->toArray()['props']['slots'];
    });

    return collect($slots)->map(fn (string $slot) => CarbonImmutable::parse($slot));
}

function localSlot(string $local): string
{
    return CarbonImmutable::parse($local, 'Europe/Amsterdam')->utc()->format('Y-m-d\TH:i:s\Z');
}

/** @param array<string, mixed> $overrides */
function bookingPayload(string $local = '2026-11-04 10:00', array $overrides = []): array
{
    return [
        'starts_at' => localSlot($local),
        'name' => 'Jane Visitor',
        'email' => 'jane@example.test',
        'note' => 'Would like to talk about a project',
        'timezone' => 'Europe/London',
        ...$overrides,
    ];
}

/** @param array<string, mixed> $attributes */
function blockingEvent(User $owner, string $from, string $to, array $attributes = []): Event
{
    $calendar = $owner->calendars()->first() ?? Calendar::factory()->for($owner)->create();

    return Event::factory()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse($from, 'Europe/Amsterdam')->utc(),
        'ends_at' => CarbonImmutable::parse($to, 'Europe/Amsterdam')->utc(),
        ...$attributes,
    ]);
}

/** @return Collection<int, Email> */
function sentEmails(): Collection
{
    return collect(Mail::getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage());
}

function emailTo(string $address): ?Email
{
    return sentEmails()->first(
        fn (Email $email) => collect($email->getTo())->contains(fn ($a) => $a->getAddress() === $address),
    );
}

function manageUrlFrom(Email $email): string
{
    preg_match('#https?://[^\s"<>]+/book/booking/[^\s"<>]+#', (string) $email->getTextBody(), $matches);

    return html_entity_decode($matches[0]);
}

function bookedSlot(string $local = '2026-11-04 10:00'): Booking
{
    test()->post('/book', bookingPayload($local))->assertRedirect();

    return Booking::query()->latest('id')->firstOrFail();
}

const CMS_WEBHOOK_SECRET = 'test-cms-secret-9f2c1d';

function configureCmsWebhook(?string $url = 'https://cms.example.test', ?string $secret = CMS_WEBHOOK_SECRET): void
{
    config(['services.cms.webhook_url' => $url, 'services.cms.webhook_secret' => $secret]);
}

/** @return Collection<int, Request> */
function cmsWebhookRequests(): Collection
{
    return Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();
}

/**
 * Recomputes the signature from the bytes the fake recorded, independently of
 * the code under test.
 */
function expectSignedCmsWebhook(Request $request, string $secret = CMS_WEBHOOK_SECRET): void
{
    $timestamp = $request->header('X-Chronos-Timestamp')[0] ?? '';
    $signature = $request->header('X-Chronos-Signature')[0] ?? '';

    expect($timestamp)->toMatch('/^\d{10}$/')
        ->and($signature)->toMatch('/^[0-9a-f]{64}$/')
        ->and(hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret), $signature))->toBeTrue();
}

/** @return array{event: string, booking: array<string, mixed>} */
function cmsWebhookBody(Request $request): array
{
    return json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
}
