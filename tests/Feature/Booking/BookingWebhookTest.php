<?php

use App\Jobs\SendBookingWebhookJob;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->owner = bookingOwner();
    introCall($this->owner);
    atMondayMorning();
    configureCmsWebhook();
    Http::preventStrayRequests();
});

function signedCancelUrl(Booking $booking): string
{
    return URL::temporarySignedRoute('book.cancel', CarbonImmutable::parse('+1 week', 'Europe/Amsterdam'), ['booking' => $booking->uuid]);
}

/** Production queues to the database; the test default runs jobs inline. */
function queueToDatabase(): void
{
    config(['queue.default' => 'database']);
}

function drainQueue(): void
{
    Artisan::call('queue:work', ['--stop-when-empty' => true]);
}

/** @return Collection<int, stdClass> */
function waitingWebhookJobs(): Collection
{
    return DB::table('jobs')->get()
        ->filter(fn (stdClass $job): bool => str_contains((string) $job->payload, class_basename(SendBookingWebhookJob::class)))
        ->values();
}

it('sends a signed booking.created to the CMS when a visitor books', function () {
    Http::fake(['*' => Http::response(status: 204)]);

    $this->post('/book', bookingPayload('2026-11-04 10:00', ['phone' => '+31 6 12345678']))->assertRedirect();

    $booking = Booking::query()->firstOrFail();
    $requests = cmsWebhookRequests();

    expect($requests)->toHaveCount(1);

    $request = $requests->first();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://cms.example.test/webhooks/chronos/bookings')
        ->and($request->header('Content-Type'))->toBe(['application/json'])
        ->and($request->header('X-Chronos-Timestamp'))->toBe([(string) now()->getTimestamp()]);

    expectSignedCmsWebhook($request);

    expect(cmsWebhookBody($request))->toBe([
        'event' => 'booking.created',
        'booking' => [
            'uuid' => $booking->uuid,
            'type' => 'Intro call',
            'starts_at' => '2026-11-04T09:00:00Z',
            'ends_at' => '2026-11-04T09:20:00Z',
            'guest_name' => 'Jane Visitor',
            'guest_email' => 'jane@example.test',
            'guest_phone' => '+31 6 12345678',
            'guest_note' => 'Would like to talk about a project',
            'guest_timezone' => 'Europe/London',
            'locale' => 'en',
            'cancelled_at' => null,
            'created_at' => '2026-11-02T07:00:00Z',
        ],
    ]);
});

it('sends null for a missing phone and a missing note', function () {
    Http::fake(['*' => Http::response(status: 204)]);

    $this->post('/book', bookingPayload('2026-11-04 10:00', ['note' => null]))->assertRedirect();

    $request = cmsWebhookRequests()->first();
    $body = cmsWebhookBody($request);

    expectSignedCmsWebhook($request);

    expect($body['booking'])->toHaveKeys(['guest_phone', 'guest_note', 'cancelled_at'])
        ->and($body['booking']['guest_phone'])->toBeNull()
        ->and($body['booking']['guest_note'])->toBeNull()
        ->and($body['booking']['cancelled_at'])->toBeNull();
});

it('signs exactly the bytes it sends when the name and note hold Unicode and quotes', function () {
    Http::fake(['*' => Http::response(status: 204)]);

    $name = 'Zoë "Zo" O\'Brien-Çelik 日本 😀';
    $note = 'He said: "c\'est l\'été" </script> \\ / & <b>€5</b> 🚀';

    $this->post('/book', bookingPayload('2026-11-04 10:00', ['name' => $name, 'note' => $note]))->assertRedirect();

    $request = cmsWebhookRequests()->first();

    expectSignedCmsWebhook($request);

    expect(cmsWebhookBody($request)['booking']['guest_name'])->toBe($name)
        ->and(cmsWebhookBody($request)['booking']['guest_note'])->toBe($note);
});

it('does not double the slash when the configured URL ends in one', function () {
    configureCmsWebhook('https://cms.example.test/');
    Http::fake(['*' => Http::response(status: 204)]);

    bookedSlot();

    expect(cmsWebhookRequests()->first()->url())->toBe('https://cms.example.test/webhooks/chronos/bookings');
});

it('sends nothing and fails nothing when the URL or the secret is not configured', function (?string $url, ?string $secret) {
    configureCmsWebhook($url, $secret);
    Http::fake();
    queueToDatabase();

    $booking = bookedSlot();
    $this->post(signedCancelUrl($booking))->assertRedirect();
    drainQueue();

    Http::assertNothingSent();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($booking->fresh()->cancelled_at)->not->toBeNull();
})->with([
    'nothing configured' => [null, null],
    'no URL' => [null, CMS_WEBHOOK_SECRET],
    'empty URL' => ['', CMS_WEBHOOK_SECRET],
    'no secret' => ['https://cms.example.test', null],
    'empty secret' => ['https://cms.example.test', ''],
]);

it('sends one booking.cancelled when a visitor cancels, with the cancel time', function () {
    Http::fake(['*' => Http::response(status: 204)]);

    $booking = bookedSlot('2026-11-04 10:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:05', 'UTC'));

    $this->post(signedCancelUrl($booking))->assertRedirect();

    $requests = cmsWebhookRequests();
    $cancelled = $requests->last();
    $body = cmsWebhookBody($cancelled);

    expect($requests)->toHaveCount(2)
        ->and($cancelled->header('X-Chronos-Timestamp'))->toBe([(string) now()->getTimestamp()])
        ->and($body['event'])->toBe('booking.cancelled')
        ->and($body['booking']['uuid'])->toBe($booking->uuid)
        ->and($body['booking']['cancelled_at'])->toBe('2026-11-03T12:00:05Z')
        ->and($body['booking']['starts_at'])->toBe('2026-11-04T09:00:00Z');

    expectSignedCmsWebhook($cancelled);
});

it('sends one cancelled event when the cancel link is clicked twice', function () {
    Http::fake(['*' => Http::response(status: 204)]);

    $booking = bookedSlot();
    $url = signedCancelUrl($booking);

    $this->post($url)->assertRedirect();
    $this->post($url)->assertRedirect();

    $events = cmsWebhookRequests()->map(fn ($request) => cmsWebhookBody($request)['event'])->all();

    expect($events)->toBe(['booking.created', 'booking.cancelled']);
});

it('sends from the queue, not from the visitor request', function () {
    Http::fake(['*' => Http::response(status: 204)]);
    queueToDatabase();

    $booking = bookedSlot();
    $this->post(signedCancelUrl($booking))->assertRedirect();

    Http::assertNothingSent();
    expect(waitingWebhookJobs())->toHaveCount(2);

    drainQueue();

    expect(cmsWebhookRequests())->toHaveCount(2)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('still books and cancels when the CMS fails, and keeps the job for a retry', function (Closure $response) {
    Http::fake(['*' => $response]);
    queueToDatabase();

    $this->post('/book', bookingPayload())->assertRedirect()->assertSessionHasNoErrors();
    $booking = Booking::query()->firstOrFail();
    $this->post(signedCancelUrl($booking))->assertRedirect();

    drainQueue();

    $waiting = waitingWebhookJobs();

    expect($booking->fresh()->cancelled_at)->not->toBeNull()
        ->and(emailTo('jane@example.test'))->not->toBeNull()
        ->and($waiting)->toHaveCount(2)
        ->and($waiting->pluck('attempts')->all())->toBe([1, 1])
        ->and($waiting->min('available_at'))->toBeGreaterThan(now()->getTimestamp())
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with([
    'a 500' => [fn () => Http::response('boom', 500)],
    'a 401' => [fn () => Http::response('', 401)],
    'a 422' => [fn () => Http::response(['message' => 'rejected'], 422)],
    'a redirect' => [fn () => Http::response('', 302, ['Location' => 'https://elsewhere.example.test'])],
    'a timeout or a refused connection' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
]);

it('still books when the CMS fails and jobs run inline', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $this->post('/book', bookingPayload())->assertRedirect()->assertSessionHasNoErrors();
    $booking = Booking::query()->firstOrFail();
    $this->post(signedCancelUrl($booking))->assertRedirect();

    expect($booking->fresh()->cancelled_at)->not->toBeNull();
});

it('still books when the queue cannot take the job, and reports it', function () {
    Exceptions::fake();

    // A proxy around the real bus: the mails still go through it untouched.
    $bus = Mockery::mock(Bus::getFacadeRoot());
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue is down'));
    Bus::swap($bus);

    $this->post('/book', bookingPayload())->assertRedirect()->assertSessionHasNoErrors();
    $booking = Booking::query()->firstOrFail();
    $this->post(signedCancelUrl($booking))->assertRedirect();

    expect($booking->fresh()->cancelled_at)->not->toBeNull()
        ->and(emailTo('jane@example.test'))->not->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'queue is down');
});

it('never tells the CMS a cancelled booking was created, whatever order the tries land in', function () {
    Http::fake(['*' => Http::sequence()->pushStatus(500)->pushStatus(204)->pushStatus(204)]);
    queueToDatabase();

    $booking = bookedSlot();
    drainQueue();

    $this->post(signedCancelUrl($booking))->assertRedirect();
    $this->travel(2)->minutes();
    drainQueue();

    $bodies = cmsWebhookRequests()->map(fn ($request) => cmsWebhookBody($request));

    expect($bodies)->toHaveCount(3)
        ->and($bodies[0]['event'])->toBe('booking.created')
        ->and($bodies[0]['booking']['cancelled_at'])->toBeNull()
        ->and($bodies[1]['event'])->toBe('booking.cancelled')
        ->and($bodies[2]['event'])->toBe('booking.cancelled')
        ->and($bodies[1]['booking']['cancelled_at'])->not->toBeNull()
        ->and($bodies[2]['booking']['cancelled_at'])->toBe($bodies[1]['booking']['cancelled_at'])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('does not follow redirects and gives up on a slow CMS quickly', function () {
    $options = [];

    Http::fake(function ($request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(status: 204);
    });

    bookedSlot();

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['connect_timeout'])->toBe(5)
        ->and($options['timeout'])->toBe(10);
});

it('delivers on a later try with a fresh timestamp and signature', function () {
    Http::fake(['*' => Http::sequence()->pushStatus(500)->pushStatus(204)]);
    queueToDatabase();

    bookedSlot();
    drainQueue();

    $this->travel(2)->minutes();
    drainQueue();

    $requests = cmsWebhookRequests();
    $timestamps = $requests->map(fn ($request) => (int) $request->header('X-Chronos-Timestamp')[0]);

    expect($requests)->toHaveCount(2)
        ->and($timestamps[1] - $timestamps[0])->toBe(120)
        ->and($requests[0]->body())->toBe($requests[1]->body())
        ->and($requests[0]->header('X-Chronos-Signature'))->not->toBe($requests[1]->header('X-Chronos-Signature'))
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $requests->each(fn ($request) => expectSignedCmsWebhook($request));
});

it('gives up after eight tries spread over about a day, without leaking the secret', function () {
    Http::fake(['*' => Http::response('nope', 401)]);
    queueToDatabase();

    bookedSlot();
    $start = now();

    foreach (range(1, 7) as $try) {
        drainQueue();

        expect(waitingWebhookJobs())->toHaveCount(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        $this->travelTo(CarbonImmutable::createFromTimestamp(waitingWebhookJobs()->first()->available_at));
    }

    drainQueue();

    $failed = DB::table('failed_jobs')->get();

    expect(cmsWebhookRequests())->toHaveCount(8)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($failed)->toHaveCount(1)
        ->and($failed->first()->payload)->toContain('SendBookingWebhookJob')->not->toContain(CMS_WEBHOOK_SECRET)
        ->and($failed->first()->exception)->toContain('401')->not->toContain(CMS_WEBHOOK_SECRET)
        ->and($start->diffInHours(now()))->toBeGreaterThan(20)->toBeLessThan(28);
});

it('keeps the secret out of the queued job', function () {
    Http::fake();
    queueToDatabase();

    bookedSlot();

    expect(waitingWebhookJobs())->toHaveCount(1)
        ->and(waitingWebhookJobs()->first()->payload)->not->toContain(CMS_WEBHOOK_SECRET);
});
