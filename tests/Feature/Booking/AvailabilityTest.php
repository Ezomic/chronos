<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->owner = bookingOwner();
    introCall($this->owner);
    atMondayMorning();
});

/** @param Collection<int, CarbonImmutable> $slots */
function localTimes(Collection $slots): array
{
    return $slots->map(fn ($slot) => $slot->setTimezone('Europe/Amsterdam')->format('D H:i'))->all();
}

function hasSlotAt(string $local): bool
{
    return bookableSlots()->contains(
        fn ($s) => $s->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i') === $local,
    );
}

it('offers weekday slots between 09:00 and 17:00 Amsterdam time, never weekends', function () {
    $slots = bookableSlots();

    expect($slots)->not->toBeEmpty();

    foreach ($slots as $slot) {
        $local = $slot->setTimezone('Europe/Amsterdam');

        expect($local->isWeekend())->toBeFalse()
            ->and($local->format('H:i'))->toBeGreaterThanOrEqual('09:00')
            ->and($local->addMinutes(20)->format('H:i'))->toBeLessThanOrEqual('17:00');
    }
});

it('needs no login and sends nothing private', function () {
    blockingEvent($this->owner, '2026-11-04 10:00', '2026-11-04 11:00', [
        'title' => 'Secret board meeting',
        'description' => 'Salary talks',
        'location' => 'Hidden room',
    ]);

    $response = $this->get('/book')->assertOk();

    expect($response->getContent())
        ->not->toContain('Secret board')
        ->not->toContain('Salary')
        ->not->toContain('Hidden room')
        ->not->toContain('owner@example.test');
});

it('honours the 24 hour minimum notice', function () {
    $slots = bookableSlots();

    expect($slots->first()->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i'))->toBe('2026-11-03 09:00')
        ->and(hasSlotAt('2026-11-02 16:30'))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:45', 'Europe/Amsterdam'));

    expect(hasSlotAt('2026-11-03 09:30'))->toBeFalse()
        ->and(hasSlotAt('2026-11-03 10:00'))->toBeTrue();
});

it('stops offering slots 30 days ahead', function () {
    $last = bookableSlots()->last();

    expect($last->lessThanOrEqualTo(now()->addDays(30)))->toBeTrue()
        ->and($last->greaterThan(now()->addDays(25)))->toBeTrue();
});

it('blocks an event plus a 15 minute buffer on both sides', function () {
    blockingEvent($this->owner, '2026-11-04 10:00', '2026-11-04 11:00');

    expect(hasSlotAt('2026-11-04 09:00'))->toBeTrue()
        ->and(hasSlotAt('2026-11-04 09:30'))->toBeFalse()
        ->and(hasSlotAt('2026-11-04 10:00'))->toBeFalse()
        ->and(hasSlotAt('2026-11-04 10:30'))->toBeFalse()
        ->and(hasSlotAt('2026-11-04 11:00'))->toBeFalse()
        ->and(hasSlotAt('2026-11-04 11:30'))->toBeTrue();
});

it('blocks events mirrored from Google', function () {
    $mirrored = Calendar::factory()->mirrored()->for($this->owner)->create();
    blockingEvent($this->owner, '2026-11-04 14:00', '2026-11-04 15:00', [
        'calendar_id' => $mirrored->id,
        'external_id' => 'g-1',
    ]);

    expect(hasSlotAt('2026-11-04 14:00'))->toBeFalse()
        ->and(hasSlotAt('2026-11-04 14:30'))->toBeFalse()
        ->and(hasSlotAt('2026-11-04 13:00'))->toBeTrue();
});

it('does not block on an event marked free', function () {
    blockingEvent($this->owner, '2026-11-04 14:00', '2026-11-04 15:00', ['is_free' => true]);

    expect(hasSlotAt('2026-11-04 14:00'))->toBeTrue()->and(hasSlotAt('2026-11-04 14:30'))->toBeTrue();
});

it('does not block on a soft-deleted event', function () {
    blockingEvent($this->owner, '2026-11-04 14:00', '2026-11-04 15:00')->delete();

    expect(hasSlotAt('2026-11-04 14:00'))->toBeTrue();
});

it('blocks on events in hidden calendars too', function () {
    $hidden = Calendar::factory()->for($this->owner)->create(['is_visible' => false]);
    blockingEvent($this->owner, '2026-11-04 14:00', '2026-11-04 15:00', ['calendar_id' => $hidden->id]);

    expect(hasSlotAt('2026-11-04 14:00'))->toBeFalse();
});

it('ignores events that belong to someone else', function () {
    $stranger = User::factory()->create();
    blockingEvent($stranger, '2026-11-04 14:00', '2026-11-04 15:00');

    expect(hasSlotAt('2026-11-04 14:00'))->toBeTrue();
});

it('blocks the whole day for an all-day event', function () {
    $calendar = Calendar::factory()->for($this->owner)->create();
    Event::factory()->allDay()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-11-04', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-05', 'UTC'),
    ]);

    expect(hasSlotAt('2026-11-04 09:00'))->toBeFalse()
        ->and(hasSlotAt('2026-11-04 16:30'))->toBeFalse()
        ->and(hasSlotAt('2026-11-03 16:30'))->toBeTrue()
        ->and(hasSlotAt('2026-11-05 09:00'))->toBeTrue();
});

it('lets a free all-day event through, like a Google birthday', function () {
    $calendar = Calendar::factory()->for($this->owner)->create();
    Event::factory()->allDay()->for($calendar)->create([
        'starts_at' => CarbonImmutable::parse('2026-11-04', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-05', 'UTC'),
        'is_free' => true,
    ]);

    expect(hasSlotAt('2026-11-04 09:00'))->toBeTrue();
});

it('blocks each occurrence of a recurring event, minus excluded dates', function () {
    blockingEvent($this->owner, '2026-11-04 15:00', '2026-11-04 16:00', [
        'rrule' => 'FREQ=WEEKLY;COUNT=3',
        'excluded_dates' => ['2026-11-11 14:00:00'],
    ]);

    expect(hasSlotAt('2026-11-04 15:00'))->toBeFalse()
        ->and(hasSlotAt('2026-11-11 15:00'))->toBeTrue()
        ->and(hasSlotAt('2026-11-18 15:00'))->toBeFalse()
        ->and(hasSlotAt('2026-11-25 15:00'))->toBeTrue();
});

it('follows an occurrence that was moved by an override', function () {
    $series = blockingEvent($this->owner, '2026-11-04 15:00', '2026-11-04 16:00', ['rrule' => 'FREQ=WEEKLY;COUNT=3']);
    blockingEvent($this->owner, '2026-11-11 11:00', '2026-11-11 12:00', [
        'overrides_event_id' => $series->id,
        'overrides_starts_at' => CarbonImmutable::parse('2026-11-11 15:00', 'Europe/Amsterdam')->utc(),
    ]);

    expect(hasSlotAt('2026-11-11 15:00'))->toBeTrue()
        ->and(hasSlotAt('2026-11-11 11:00'))->toBeFalse()
        ->and(hasSlotAt('2026-11-18 15:00'))->toBeFalse();
});

it('keeps 09:00 local across the October DST change', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00', 'Europe/Amsterdam'));

    $slots = bookableSlots();
    $friday = $slots->first(fn ($s) => $s->setTimezone('Europe/Amsterdam')->format('Y-m-d') === '2026-10-23');
    $monday = $slots->first(fn ($s) => $s->setTimezone('Europe/Amsterdam')->format('Y-m-d') === '2026-10-26');

    expect($friday->utc()->format('H:i'))->toBe('07:00')
        ->and($monday->utc()->format('H:i'))->toBe('08:00');
});

it('keeps 09:00 local across the March DST change', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-25 08:00', 'Europe/Amsterdam'));

    $slots = bookableSlots();
    $friday = $slots->first(fn ($s) => $s->setTimezone('Europe/Amsterdam')->format('Y-m-d') === '2027-03-26');
    $monday = $slots->first(fn ($s) => $s->setTimezone('Europe/Amsterdam')->format('Y-m-d') === '2027-03-29');

    expect($friday->utc()->format('H:i'))->toBe('08:00')
        ->and($monday->utc()->format('H:i'))->toBe('07:00');
});

it('offers nothing when the owner has no weekly hours', function () {
    introCall($this->owner)->windows()->delete();

    expect(bookableSlots())->toBeEmpty();
});

it('is a 404 when nobody owns the app yet', function () {
    Calendar::query()->delete();
    User::query()->delete();

    $this->get('/book')->assertNotFound();
});

it('follows the visitor language, with ?lang winning', function () {
    $locale = function (array $headers = [], string $query = '') {
        $locale = null;
        $this->flushHeaders()->withHeaders($headers)->get('/book'.$query)->assertInertia(function ($page) use (&$locale) {
            $locale = $page->toArray()['props']['locale'];
        });

        return $locale;
    };

    expect($locale())->toBe('en')
        ->and($locale(['Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.5']))->toBe('nl')
        ->and($locale(['Accept-Language' => 'nl-NL'], '?lang=en'))->toBe('en')
        ->and($locale([], '?lang=nl'))->toBe('nl')
        ->and($locale([], '?lang=fr'))->toBe('en')
        ->and($locale([], '?lang[]=nl'))->toBe('en');
});
