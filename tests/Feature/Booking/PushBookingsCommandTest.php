<?php

use App\Actions\CancelBookingAction;
use App\Models\Booking;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->owner = bookingOwner();
    introCall($this->owner);
    atMondayMorning();
    Http::preventStrayRequests();

    // Booked while nothing is configured: these are the bookings from before
    // the webhook existed, which is what the command is for.
    configureCmsWebhook(null, null);
    $this->first = bookedSlot('2026-11-04 10:00');
    $this->second = bookedSlot('2026-11-05 10:00');
    $this->cancelled = bookedSlot('2026-11-06 10:00');
    app(CancelBookingAction::class)->handle($this->cancelled);

    configureCmsWebhook();
});

function cmsAccepts(): void
{
    Http::fake(['*' => Http::response(status: 204)]);
}

/** @return array<int, array{string, string}> */
function pushedEvents(): array
{
    return cmsWebhookRequests()
        ->map(fn ($request) => [cmsWebhookBody($request)['event'], cmsWebhookBody($request)['booking']['uuid']])
        ->all();
}

it('sends one booking by uuid and leaves the others alone', function () {
    cmsAccepts();

    $this->artisan('chronos:push-bookings', ['booking' => [$this->second->uuid]])
        ->expectsOutputToContain($this->second->uuid)
        ->assertSuccessful();

    expect(pushedEvents())->toBe([['booking.created', $this->second->uuid]]);

    expectSignedCmsWebhook(cmsWebhookRequests()->first());
});

it('accepts ids and uuids together', function () {
    cmsAccepts();

    $this->artisan('chronos:push-bookings', ['booking' => [(string) $this->first->id, $this->second->uuid]])
        ->assertSuccessful();

    expect(pushedEvents())->toBe([
        ['booking.created', $this->first->uuid],
        ['booking.created', $this->second->uuid],
    ]);
});

it('sends booking.cancelled for a cancelled booking', function () {
    cmsAccepts();

    $this->artisan('chronos:push-bookings', ['booking' => [$this->cancelled->uuid]])->assertSuccessful();

    $request = cmsWebhookRequests()->first();

    expect(pushedEvents())->toBe([['booking.cancelled', $this->cancelled->uuid]])
        ->and(cmsWebhookBody($request)['booking']['cancelled_at'])->toBe('2026-11-02T07:00:00Z');

    expectSignedCmsWebhook($request);
});

it('sends every booking with --all', function () {
    cmsAccepts();

    $this->artisan('chronos:push-bookings', ['--all' => true])->assertSuccessful();

    expect(pushedEvents())->toBe([
        ['booking.created', $this->first->uuid],
        ['booking.created', $this->second->uuid],
        ['booking.cancelled', $this->cancelled->uuid],
    ]);
});

it('sends nothing at all when one of the given bookings is unknown', function (string $unknown) {
    cmsAccepts();

    $this->artisan('chronos:push-bookings', ['booking' => [$this->first->uuid, $unknown]])
        ->expectsOutputToContain($unknown)
        ->assertFailed();

    Http::assertNothingSent();
})->with(['999999', 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'not-a-booking']);

it('refuses to guess when given neither bookings nor --all, or both', function () {
    cmsAccepts();

    $this->artisan('chronos:push-bookings')->assertFailed();
    $this->artisan('chronos:push-bookings', ['booking' => [$this->first->uuid], '--all' => true])->assertFailed();

    Http::assertNothingSent();
});

it('sends nothing and says so when the webhook is not configured', function (?string $url, ?string $secret) {
    cmsAccepts();
    configureCmsWebhook($url, $secret);

    $this->artisan('chronos:push-bookings', ['--all' => true])
        ->expectsOutputToContain('CMS_WEBHOOK_URL')
        ->assertFailed();

    Http::assertNothingSent();
})->with([
    'no URL' => [null, CMS_WEBHOOK_SECRET],
    'no secret' => ['https://cms.example.test', null],
]);

it('reports a booking the CMS refuses, carries on with the rest, and fails', function () {
    Http::fake(['*' => Http::sequence()->pushStatus(422)->pushStatus(204)->pushStatus(204)]);

    $this->artisan('chronos:push-bookings', ['--all' => true])
        ->expectsOutputToContain('422')
        ->doesntExpectOutputToContain(CMS_WEBHOOK_SECRET)
        ->assertFailed();

    expect(cmsWebhookRequests())->toHaveCount(3)
        ->and(Booking::query()->count())->toBe(3);
});
