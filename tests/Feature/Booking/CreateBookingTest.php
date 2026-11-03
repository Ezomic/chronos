<?php

use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Calendar;
use App\Models\Event;
use App\Services\Booking\SlotFinder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Part\DataPart;

beforeEach(function () {
    $this->owner = bookingOwner();
    introCall($this->owner);
    atMondayMorning();
});

it('books a slot as a normal event in a Bookings calendar, stored in UTC', function () {
    $response = $this->post('/book', bookingPayload('2026-11-04 10:00'));

    $booking = Booking::query()->firstOrFail();

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/book/booking/'.$booking->uuid)->toContain('signature=');

    $event = $booking->event;
    expect($booking->starts_at->format('Y-m-d H:i'))->toBe('2026-11-04 09:00')
        ->and($booking->ends_at->format('Y-m-d H:i'))->toBe('2026-11-04 09:20')
        ->and($booking->guest_name)->toBe('Jane Visitor')
        ->and($booking->guest_email)->toBe('jane@example.test')
        ->and($booking->guest_timezone)->toBe('Europe/London')
        ->and($event->starts_at->equalTo($booking->starts_at))->toBeTrue()
        ->and($event->calendar->name)->toBe('Bookings')
        ->and($event->calendar->user_id)->toBe($this->owner->id)
        ->and($event->calendar->is_writable)->toBeTrue()
        ->and($event->title)->toContain('Jane Visitor')
        ->and($event->is_free)->toBeFalse();
});

it('creates the Bookings calendar once, on demand', function () {
    expect(Calendar::where('name', 'Bookings')->count())->toBe(0);

    bookedSlot('2026-11-04 10:00');
    bookedSlot('2026-11-05 10:00');

    expect(Calendar::where('name', 'Bookings')->count())->toBe(1);
});

it('takes the slot off the page once booked', function () {
    bookedSlot('2026-11-04 10:00');

    expect(bookableSlots()->map->format('c')->all())
        ->not->toContain(CarbonImmutable::parse(localSlot('2026-11-04 10:00'))->format('c'));
});

it('emails the visitor and the owner, each with an ICS attachment', function () {
    bookedSlot('2026-11-04 10:00');

    $visitor = emailTo('jane@example.test');
    $owner = emailTo('owner@example.test');

    expect($visitor)->not->toBeNull()->and($owner)->not->toBeNull();

    foreach ([$visitor, $owner] as $email) {
        $ics = collect($email->getAttachments())->first(fn (DataPart $p) => $p->getMediaType() === 'text' && $p->getMediaSubtype() === 'calendar');

        expect($ics)->not->toBeNull()
            ->and($ics->getBody())->toContain('BEGIN:VCALENDAR')->toContain('DTSTART:20261104T090000Z')->toContain('DTEND:20261104T092000Z');
    }

    expect((string) $owner->getTextBody())->toContain('Jane Visitor')->toContain('Would like to talk about a project')
        ->and((string) $visitor->getTextBody())->toContain('/book/booking/');
});

it('writes the visitor email in the visitor language', function () {
    $this->post('/book?lang=nl', bookingPayload('2026-11-04 10:00'))->assertRedirect();

    $mail = emailTo('jane@example.test');

    expect($mail->getSubject())->toContain('bevestigd')
        ->and($mail->getTextBody())->toContain('woensdag 4 november 2026, 09:00 (Europe/London)');
});

it('keeps Unicode names and notes intact', function () {
    $this->post('/book', bookingPayload('2026-11-04 10:00', ['name' => 'José Núñez 李雷', 'note' => 'Café ☕ gesprek']))->assertRedirect();

    expect(Booking::first()->guest_name)->toBe('José Núñez 李雷')
        ->and(Booking::first()->guest_note)->toBe('Café ☕ gesprek');
});

it('accepts a booking without a note', function () {
    $this->post('/book', bookingPayload('2026-11-04 10:00', ['note' => '']))->assertRedirect();

    expect(Booking::first()->guest_note)->toBeNull();
});

it('drops a note that is only punctuation', function () {
    $this->post('/book', bookingPayload('2026-11-04 10:00', ['note' => '  ?!...--  ']))->assertRedirect();

    expect(Booking::first()->guest_note)->toBeNull();
});

it('turns newlines in the name into spaces, so a name cannot add mail headers', function () {
    $this->post('/book', bookingPayload('2026-11-04 10:00', ['name' => "Bob\r\nBcc: evil@example.test"]))->assertRedirect();

    $email = emailTo('jane@example.test');

    expect(Booking::first()->guest_name)->not->toContain("\n")
        ->and($email->getBcc())->toBeEmpty()
        ->and(sentEmails()->flatMap(fn ($e) => $e->getTo())->map->getAddress()->all())->not->toContain('evil@example.test');
});

it('rejects a note with line breaks, it is one line', function () {
    $this->post('/book', bookingPayload('2026-11-04 10:00', ['note' => "line one\nline two"]))->assertSessionHasErrors('note');
});

dataset('bad bookings', [
    'missing name' => [['name' => ''], 'name'],
    'whitespace name' => [['name' => '   '], 'name'],
    'punctuation-only name' => [['name' => '!!!'], 'name'],
    'too long name' => [['name' => str_repeat('a', 101)], 'name'],
    'missing email' => [['email' => ''], 'email'],
    'not an email' => [['email' => 'nope'], 'email'],
    'no domain' => [['email' => 'jane@'], 'email'],
    'two at signs' => [['email' => 'a@b@example.test'], 'email'],
    'spaces in email' => [['email' => 'ja ne@example.test'], 'email'],
    'too long email' => [['email' => str_repeat('a', 250).'@example.test'], 'email'],
    'too long note' => [['note' => str_repeat('n', 301)], 'note'],
    'array note' => [['note' => ['x']], 'note'],
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'missing timezone' => [['timezone' => ''], 'timezone'],
    'missing slot' => [['starts_at' => ''], 'starts_at'],
    'garbage slot' => [['starts_at' => 'not-a-date'], 'starts_at'],
    'slot without zone' => [['starts_at' => '2026-11-04 09:00:00'], 'starts_at'],
    'slot in the past' => [['starts_at' => '2026-10-30T09:00:00Z'], 'starts_at'],
    'slot inside 24h notice' => [['starts_at' => '2026-11-02T14:00:00Z'], 'starts_at'],
    'slot beyond 30 days' => [['starts_at' => '2026-12-09T09:00:00Z'], 'starts_at'],
    'before opening hours' => [['starts_at' => '2026-11-04T07:30:00Z'], 'starts_at'],
    'after closing hours' => [['starts_at' => '2026-11-04T16:30:00Z'], 'starts_at'],
    'ends after closing' => [['starts_at' => '2026-11-04T15:50:00Z'], 'starts_at'],
    'off the slot grid' => [['starts_at' => '2026-11-04T09:10:00Z'], 'starts_at'],
    'weekend' => [['starts_at' => '2026-11-07T09:00:00Z'], 'starts_at'],
    'one hour early after DST end (08:00 CET)' => [['starts_at' => '2026-10-26T07:00:00Z'], 'starts_at'],
    'honeypot filled' => [['website' => 'http://spam.example'], 'website'],
]);

it('refuses bad input and books nothing', function (array $override, string $field) {
    $this->post('/book', bookingPayload('2026-11-04 10:00', $override))->assertSessionHasErrors($field);

    expect(Booking::count())->toBe(0)
        ->and(Event::count())->toBe(0)
        ->and(sentEmails())->toBeEmpty();
})->with('bad bookings');

it('refuses a slot an existing event blocks', function () {
    blockingEvent($this->owner, '2026-11-04 10:00', '2026-11-04 11:00');

    $this->post('/book', bookingPayload('2026-11-04 10:00'))->assertSessionHasErrors('starts_at');

    expect(Booking::count())->toBe(0);
});

it('refuses a second booking of the same slot', function () {
    bookedSlot('2026-11-04 10:00');

    $this->post('/book', bookingPayload('2026-11-04 10:00', ['email' => 'other@example.test']))
        ->assertSessionHasErrors('starts_at');

    expect(Booking::count())->toBe(1)->and(emailTo('other@example.test'))->toBeNull();
});

it('refuses a slot that sits inside the buffer of another booking', function () {
    bookedSlot('2026-11-04 10:00');

    $this->post('/book', bookingPayload('2026-11-04 10:30'))->assertSessionHasErrors('starts_at');
    $this->post('/book', bookingPayload('2026-11-04 11:00'))->assertRedirect();
});

it('books 09:00 on the DST change day at the right UTC instant', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00', 'Europe/Amsterdam'));

    $this->post('/book', bookingPayload('2026-10-26 09:00'))->assertRedirect();

    expect(Booking::first()->starts_at->format('Y-m-d H:i'))->toBe('2026-10-26 08:00');
});

it('cannot double book even when two visitors pass the availability check together', function () {
    $this->app->instance(SlotFinder::class, new class extends SlotFinder
    {
        public function __construct() {}

        public function isBookable(BookingType $type, CarbonImmutable $startsAt): bool
        {
            return true;
        }
    });

    $this->post('/book', bookingPayload('2026-11-04 10:00'))->assertRedirect();
    $this->post('/book', bookingPayload('2026-11-04 10:00', ['email' => 'other@example.test']))
        ->assertSessionHasErrors('starts_at');

    expect(Booking::count())->toBe(1)->and(Event::count())->toBe(1);
});

it('has a database-level guard on an active slot', function () {
    $booking = bookedSlot('2026-11-04 10:00');

    expect(fn () => Booking::query()->create([
        ...$booking->only(['booking_type_id', 'starts_at', 'ends_at', 'guest_name', 'guest_email', 'guest_timezone', 'locale']),
        'uuid' => (string) Str::uuid(),
        'slot_key' => $booking->slot_key,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('rate limits one address after ten attempts an hour', function () {
    foreach (range(1, 10) as $i) {
        $this->post('/book', bookingPayload('2026-11-04 10:00', ['name' => '']))->assertSessionHasErrors('name');
    }

    $this->post('/book', bookingPayload('2026-11-04 10:00'))->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post('/book', bookingPayload('2026-11-04 10:00'))->assertRedirect();
});

it('keys the rate limit on the connecting address, not on a header the visitor can set', function () {
    foreach (range(1, 10) as $i) {
        $this->withHeaders(['X-Forwarded-For' => "198.51.100.{$i}"])
            ->post('/book', bookingPayload('2026-11-04 10:00', ['name' => '']))
            ->assertSessionHasErrors('name');
    }

    $this->withHeaders(['X-Forwarded-For' => '198.51.100.99'])
        ->post('/book', bookingPayload('2026-11-04 10:00'))
        ->assertTooManyRequests();
});
