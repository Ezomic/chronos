<?php

use App\Models\Booking;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->owner = bookingOwner();
    introCall($this->owner);
    atMondayMorning();
});

function cancelUrlFor(Booking $booking, string $at = '+1 week'): string
{
    return URL::temporarySignedRoute('book.cancel', CarbonImmutable::parse($at, 'Europe/Amsterdam'), ['booking' => $booking->uuid]);
}

it('shows the booking behind the signed link from the email, with the time in the visitor zone', function () {
    $booking = bookedSlot('2026-11-04 10:00');
    $url = manageUrlFrom(emailTo('jane@example.test'));

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('book/Manage')
        ->where('booking.name', 'Jane Visitor')
        ->where('booking.starts_at', '2026-11-04T09:00:00Z')
        ->where('booking.timezone', 'Europe/London')
        ->where('booking.cancelled', false)
        ->where('booking.email', 'jane@example.test')
        ->has('cancel_url'));

    expect($booking->fresh()->cancelled_at)->toBeNull();
});

it('does not cancel on a plain GET, so a mail scanner cannot do it', function () {
    $booking = bookedSlot();

    $this->get(manageUrlFrom(emailTo('jane@example.test')))->assertOk();

    expect($booking->fresh()->cancelled_at)->toBeNull();
});

it('cancels: frees the slot, removes the event, tells the owner', function () {
    $booking = bookedSlot('2026-11-04 10:00');
    $eventId = $booking->event_id;

    $this->post(cancelUrlFor($booking))->assertRedirect();

    expect($booking->fresh()->cancelled_at)->not->toBeNull()
        ->and($booking->fresh()->slot_key)->toBeNull()
        ->and(Event::find($eventId))->toBeNull()
        ->and(Event::withTrashed()->find($eventId)->trashed())->toBeTrue()
        ->and(bookableSlots()->map->format('c')->all())
        ->toContain(CarbonImmutable::parse(localSlot('2026-11-04 10:00'))->format('c'));

    $notice = sentEmails()->last();
    expect(collect($notice->getTo())->first()->getAddress())->toBe('owner@example.test')
        ->and($notice->getSubject())->toContain('Jane Visitor');
});

it('lets the same slot be booked again after a cancel', function () {
    $booking = bookedSlot('2026-11-04 10:00');
    $this->post(cancelUrlFor($booking))->assertRedirect();

    $this->post('/book', bookingPayload('2026-11-04 10:00', ['email' => 'next@example.test']))->assertRedirect();

    expect(Booking::whereNull('cancelled_at')->count())->toBe(1);
});

it('cancelling twice does not notify twice', function () {
    $booking = bookedSlot();
    $url = cancelUrlFor($booking);

    $this->post($url)->assertRedirect();
    $count = sentEmails()->count();
    $this->post($url)->assertRedirect();

    expect(sentEmails())->toHaveCount($count);
});

it('shows a cancelled booking as cancelled', function () {
    $booking = bookedSlot();
    $url = manageUrlFrom(emailTo('jane@example.test'));
    $this->post(cancelUrlFor($booking));

    $this->get($url)->assertInertia(fn ($page) => $page->where('booking.cancelled', true)->where('cancel_url', null));
});

it('refuses a cancel link without a signature', function () {
    $booking = bookedSlot();

    $this->post('/book/booking/'.$booking->uuid.'/cancel')->assertForbidden();
    $this->get('/book/booking/'.$booking->uuid)->assertForbidden();

    expect($booking->fresh()->cancelled_at)->toBeNull();
});

it('refuses a tampered signature', function () {
    $booking = bookedSlot();

    $this->post(cancelUrlFor($booking).'0')->assertForbidden();
    $this->post(str_replace('signature=', 'signature=a', cancelUrlFor($booking)))->assertForbidden();

    expect($booking->fresh()->cancelled_at)->toBeNull();
});

it('refuses an expired link', function () {
    $booking = bookedSlot('2026-11-04 10:00');
    $url = manageUrlFrom(emailTo('jane@example.test'));
    $cancel = cancelUrlFor($booking, '2026-11-04 10:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:30', 'Europe/Amsterdam'));

    $this->get($url)->assertForbidden();
    $this->post($cancel)->assertForbidden();
    expect($booking->fresh()->cancelled_at)->toBeNull();
});

it('keeps a link to one booking from touching any other event', function () {
    $mine = bookedSlot('2026-11-04 10:00');
    $theirs = bookedSlot('2026-11-05 10:00');
    $unrelated = blockingEvent($this->owner, '2026-11-06 10:00', '2026-11-06 11:00');

    $url = cancelUrlFor($mine);

    // Pointing the same signature at another booking breaks it.
    $this->post(str_replace($mine->uuid, $theirs->uuid, $url))->assertForbidden();

    $this->post($url)->assertRedirect();

    expect($theirs->fresh()->cancelled_at)->toBeNull()
        ->and(Event::find($theirs->event_id))->not->toBeNull()
        ->and(Event::find($unrelated->id))->not->toBeNull()
        ->and(Event::find($mine->event_id))->toBeNull();
});

it('does not let a manage link cancel, or a cancel link view', function () {
    $booking = bookedSlot();
    $manage = manageUrlFrom(emailTo('jane@example.test'));
    $cancelSignature = parse_url(cancelUrlFor($booking), PHP_URL_QUERY);

    $this->post(strtok($manage, '?').'/cancel?'.parse_url($manage, PHP_URL_QUERY))->assertForbidden();
    $this->get(strtok($manage, '?').'?'.$cancelSignature)->assertForbidden();

    expect($booking->fresh()->cancelled_at)->toBeNull();
});

it('is a 404 for a signed link to a booking that does not exist', function () {
    $url = URL::temporarySignedRoute('book.manage', now()->addDay(), ['booking' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479']);

    $this->get($url)->assertNotFound();
});

it('rate limits cancel attempts per address', function () {
    foreach (range(1, 10) as $i) {
        $this->post('/book/booking/'.fake()->uuid().'/cancel')->assertForbidden();
    }

    $this->post('/book/booking/'.fake()->uuid().'/cancel')->assertTooManyRequests();
});
