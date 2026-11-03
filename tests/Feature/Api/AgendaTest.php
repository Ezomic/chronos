<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

const AGENDA_KEYS = ['id', 'event_id', 'title', 'start', 'end', 'timezone', 'all_day', 'is_free', 'calendar', 'url'];

function agendaUser(string $timezone = 'Europe/Amsterdam'): User
{
    $user = User::factory()->create(['timezone' => $timezone]);
    Calendar::factory()->for($user)->default()->create(['name' => 'Personal', 'color' => '#5B5BD6']);

    return $user;
}

function agendaCalendar(User $user): Calendar
{
    return $user->calendars()->firstOrFail();
}

/** @param  array<string, mixed>  $attributes */
function agendaEvent(User $user, string $start, string $end, array $attributes = [], ?Calendar $calendar = null): Event
{
    return Event::factory()->for($calendar ?? agendaCalendar($user))->create([
        'title' => 'Event',
        'description' => null,
        'location' => null,
        'starts_at' => CarbonImmutable::parse($start, 'UTC'),
        'ends_at' => CarbonImmutable::parse($end, 'UTC'),
        'timezone' => 'Europe/Amsterdam',
        ...$attributes,
    ]);
}

/** @param  array<int, string>  $abilities */
function agendaAs(User $user, array $abilities = ['agenda:read']): void
{
    test()->withToken($user->createToken('widget', $abilities)->plainTextToken);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-20 13:00:00', 'UTC'));
});

it('serves the agenda under both /api/v1 and the unversioned alias', function (string $path) {
    $user = agendaUser();
    agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00', ['title' => 'Standup']);
    agendaAs($user);

    $this->getJson($path)->assertOk()->assertJsonPath('data.0.title', 'Standup');
})->with(['/api/v1/agenda', '/api/agenda']);

it('returns exactly the documented item shape', function () {
    $user = agendaUser();
    $event = agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00', [
        'title' => 'Standup',
        'description' => 'PRIVATE NOTES',
        'location' => 'PRIVATE ROOM',
        'source_app' => 'zero',
        'source_type' => 'email',
        'source_id' => 'MSG-1',
        'source_url' => 'https://zero.test/emails/ref/MSG-1',
        'is_free' => true,
    ]);
    agendaAs($user);

    $response = $this->getJson('/api/v1/agenda')->assertOk();
    $item = $response->json('data.0');

    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and(array_keys($response->json('meta')))->toBe(['from', 'to'])
        ->and(array_keys($item))->toBe(AGENDA_KEYS)
        ->and(array_keys($item['calendar']))->toBe(['name', 'color'])
        ->and($item['id'])->toBe("{$event->id}@2026-07-21T08:00:00Z")
        ->and($item['event_id'])->toBe($event->id)
        ->and($item['start'])->toBe('2026-07-21T08:00:00Z')
        ->and($item['end'])->toBe('2026-07-21T09:00:00Z')
        ->and($item['timezone'])->toBe('Europe/Amsterdam')
        ->and($item['all_day'])->toBeFalse()
        ->and($item['is_free'])->toBeTrue()
        ->and($item['calendar'])->toBe(['name' => 'Personal', 'color' => '#5B5BD6'])
        ->and($item['url'])->toContain('view=day')->toContain('date=2026-07-21');

    expect($response->getContent())
        ->not->toContain('PRIVATE')
        ->not->toContain('zero.test')
        ->not->toContain('MSG-1');
});

it('defaults to today in the user timezone and seven days on', function () {
    $user = agendaUser();
    agendaEvent($user, '2026-07-19 21:00', '2026-07-19 21:30', ['title' => 'Yesterday late']);
    agendaEvent($user, '2026-07-19 22:30', '2026-07-19 23:00', ['title' => 'Today early']);
    agendaEvent($user, '2026-07-26 21:30', '2026-07-26 21:45', ['title' => 'Last included']);
    agendaEvent($user, '2026-07-26 22:30', '2026-07-26 23:00', ['title' => 'Too late']);
    agendaAs($user);

    $response = $this->getJson('/api/v1/agenda')->assertOk();

    expect($response->json('meta'))->toBe(['from' => '2026-07-19T22:00:00Z', 'to' => '2026-07-26T22:00:00Z'])
        ->and(array_column($response->json('data'), 'title'))->toBe(['Today early', 'Last included']);
});

it('sorts by start ascending', function () {
    $user = agendaUser();
    agendaEvent($user, '2026-07-23 08:00', '2026-07-23 09:00', ['title' => 'C']);
    agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00', ['title' => 'A']);
    agendaEvent($user, '2026-07-22 08:00', '2026-07-22 09:00', ['title' => 'B']);
    agendaAs($user);

    expect(array_column($this->getJson('/api/v1/agenda')->json('data'), 'title'))->toBe(['A', 'B', 'C']);
});

it('answers an empty range with an empty list', function () {
    agendaAs(agendaUser());

    $this->getJson('/api/v1/agenda')->assertOk()->assertJsonPath('data', []);
});

describe('scoping', function () {
    it('never returns another user events, recurring or mirrored', function () {
        $alice = agendaUser();
        $bob = agendaUser();
        $bobMirror = Calendar::factory()->for($bob)->mirrored()->create();

        agendaEvent($alice, '2026-07-21 08:00', '2026-07-21 09:00', ['title' => 'Alice']);
        agendaEvent($bob, '2026-07-21 08:00', '2026-07-21 09:00', ['title' => 'Bob single']);
        agendaEvent($bob, '2026-07-21 10:00', '2026-07-21 11:00', ['title' => 'Bob series', 'rrule' => 'FREQ=DAILY']);
        agendaEvent($bob, '2026-07-21 12:00', '2026-07-21 13:00', ['title' => 'Bob mirror'], $bobMirror);
        agendaAs($alice);

        $response = $this->getJson('/api/v1/agenda')->assertOk();

        expect(array_column($response->json('data'), 'title'))->toBe(['Alice']);
    });

    it('includes mirrored and Bookings calendars of the token user', function () {
        $user = agendaUser();
        $mirror = Calendar::factory()->for($user)->mirrored()->create(['name' => 'Google', 'color' => '#0D9488']);
        $bookings = Calendar::factory()->for($user)->create(['name' => 'Bookings', 'color' => '#E11D48']);
        agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00', ['title' => 'Mirrored'], $mirror);
        agendaEvent($user, '2026-07-21 10:00', '2026-07-21 11:00', ['title' => 'Booked'], $bookings);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda')->json('data');

        expect(array_column($data, 'title'))->toBe(['Mirrored', 'Booked'])
            ->and($data[0]['calendar'])->toBe(['name' => 'Google', 'color' => '#0D9488'])
            ->and($data[1]['calendar'])->toBe(['name' => 'Bookings', 'color' => '#E11D48']);
    });

    it('leaves out calendars the user has hidden, as the web calendar does', function () {
        $user = agendaUser();
        $hidden = Calendar::factory()->for($user)->create(['is_visible' => false]);
        agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00', ['title' => 'Hidden'], $hidden);
        agendaAs($user);

        $this->getJson('/api/v1/agenda')->assertOk()->assertJsonPath('data', []);
    });

    it('rejects a revoked token with 401', function () {
        $user = agendaUser();
        $token = $user->createToken('widget', ['agenda:read']);
        $this->withToken($token->plainTextToken);
        $this->getJson('/api/v1/agenda')->assertOk();

        $token->accessToken->delete();
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/agenda')->assertUnauthorized();
    });

    it('rejects a missing token with 401', function () {
        $this->getJson('/api/v1/agenda')->assertUnauthorized();
    });

    it('rejects tokens without agenda:read with 403', function (array $abilities) {
        $user = agendaUser();
        agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00');
        agendaAs($user, $abilities);

        $this->getJson('/api/v1/agenda')->assertForbidden();
    })->with([
        'create only' => [['events:create']],
        'manage with app' => [['events:manage', 'app:zero']],
        'no abilities' => [[]],
    ]);

    it('lets an agenda token read without an app scope and nothing else', function () {
        $user = agendaUser();
        agendaAs($user);

        $this->getJson('/api/v1/agenda')->assertOk();
        $this->getJson('/api/v1/events')->assertForbidden();
        $this->postJson('/api/v1/events', [
            'title' => 'x',
            'starts_at' => '2026-07-21T08:00:00Z',
            'ends_at' => '2026-07-21T09:00:00Z',
        ])->assertForbidden();
    });

    it('does not widen the existing event tokens', function () {
        $user = agendaUser();
        agendaAs($user, ['events:create']);

        $this->postJson('/api/v1/events', [
            'title' => 'x',
            'starts_at' => '2026-07-21T08:00:00Z',
            'ends_at' => '2026-07-21T09:00:00Z',
        ])->assertCreated();
    });

    it('is read-only', function () {
        agendaAs(agendaUser());

        $this->postJson('/api/v1/agenda')->assertStatus(405);
        $this->deleteJson('/api/v1/agenda')->assertStatus(405);
    });
});

describe('range input', function () {
    it('reads offsets and zoneless values as instants', function (string $from, string $expected) {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?'.http_build_query(['from' => $from, 'to' => '2090-01-01T00:00:00Z']))
            ->assertStatus(422);
        $this->getJson('/api/v1/agenda?'.http_build_query(['from' => $from]))
            ->assertOk()
            ->assertJsonPath('meta.from', $expected);
    })->with([
        'utc' => ['2026-07-20T09:00:00Z', '2026-07-20T09:00:00Z'],
        'offset' => ['2026-07-20T09:00:00+02:00', '2026-07-20T07:00:00Z'],
        'no timezone' => ['2026-07-20T09:00:00', '2026-07-20T07:00:00Z'],
        'date only' => ['2026-07-20', '2026-07-19T22:00:00Z'],
        'fraction' => ['2026-07-20T09:00:00.123456Z', '2026-07-20T09:00:00Z'],
        'plus fourteen' => ['2026-07-20T00:00:00+14:00', '2026-07-19T10:00:00Z'],
        'minus twelve' => ['2026-07-20T00:00:00-12:00', '2026-07-20T12:00:00Z'],
    ]);

    it('rejects values that are not ISO 8601', function (string $field, string $value) {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?'.http_build_query([$field => $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with(function () {
        foreach (['from', 'to'] as $field) {
            foreach ([
                'word' => 'garbage',
                'relative' => 'tomorrow',
                'now' => 'now',
                'impossible month' => '2026-13-45T00:00:00Z',
                'impossible day' => '2026-02-30T00:00:00Z',
                'unix timestamp' => '1784534400',
                'space for plus' => '2026-07-20T09:00:00 02:00',
                'offset out of range' => '2026-07-20T09:00:00+99:00',
                'sql' => "2026-07-20'; DROP TABLE events;--",
                'huge' => str_repeat('9', 5000),
                'year zero' => '0000-01-01T00:00:00Z',
                'far past' => '0001-01-01T00:00:00Z',
                'far future' => '9999-12-31T00:00:00Z',
            ] as $label => $value) {
                yield "{$field} {$label}" => [$field, $value];
            }
        }
    });

    it('rejects array values', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?from[]=2026-07-20&to[a]=b')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);
    });

    it('treats empty values as missing', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?from=&to=')
            ->assertOk()
            ->assertJsonPath('meta.from', '2026-07-19T22:00:00Z');
    });

    it('defaults to when only from is given', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?from=2026-07-25T00:00:00Z')
            ->assertOk()
            ->assertJsonPath('meta', ['from' => '2026-07-25T00:00:00Z', 'to' => '2026-08-01T00:00:00Z']);
    });

    it('defaults from to the start of today when only to is given', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?to=2026-07-22T00:00:00Z')
            ->assertOk()
            ->assertJsonPath('meta', ['from' => '2026-07-19T22:00:00Z', 'to' => '2026-07-22T00:00:00Z']);
    });

    it('rejects a to that is not after from', function (string $from, string $to) {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?'.http_build_query(['from' => $from, 'to' => $to]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    })->with([
        'equal' => ['2026-07-20T00:00:00Z', '2026-07-20T00:00:00Z'],
        'equal across offsets' => ['2026-07-20T02:00:00+02:00', '2026-07-20T00:00:00Z'],
        'before' => ['2026-07-21T00:00:00Z', '2026-07-20T00:00:00Z'],
    ]);

    it('rejects a lone to that is before the default from', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?to=2026-07-01T00:00:00Z')->assertStatus(422)->assertJsonValidationErrors('to');
    });

    it('allows exactly 60 days and rejects more', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?from=2026-07-01T00:00:00Z&to=2026-08-30T00:00:00Z')->assertOk();
        $this->getJson('/api/v1/agenda?from=2026-07-01T00:00:00Z&to=2026-08-30T00:00:01Z')
            ->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/agenda?from=2026-07-01T00:00:00Z&to=2026-08-31T00:00:00Z')
            ->assertStatus(422)->assertJsonValidationErrors('to');
    });

    it('rejects a lone to beyond 60 days of the default from', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?to=2027-07-01T00:00:00Z')->assertStatus(422);
    });

    it('measures the 60 day cap on instants, not on the offset it was written in', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?from=2026-07-01T00:00:00%2B14:00&to=2026-08-29T10:00:00%2B00:00')->assertOk();
        $this->getJson('/api/v1/agenda?from=2026-07-01T00:00:00%2B14:00&to=2026-08-29T10:00:01%2B00:00')->assertStatus(422);
    });

    it('answers a far past or far future range with an empty list', function (string $from, string $to) {
        $user = agendaUser();
        agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00');
        agendaAs($user);

        $this->getJson('/api/v1/agenda?'.http_build_query(['from' => $from, 'to' => $to]))
            ->assertOk()
            ->assertJsonPath('data', []);
    })->with([
        'past' => ['1990-01-01T00:00:00Z', '1990-02-01T00:00:00Z'],
        'future' => ['2099-01-01T00:00:00Z', '2099-02-01T00:00:00Z'],
    ]);

    it('ignores unrelated query parameters', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?user_id=999&calendar_id=1&include=description')->assertOk();
    });
});

describe('time semantics', function () {
    it('includes a recurring series anchored before the range', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-01-05 08:00', '2026-01-05 09:00', ['title' => 'Weekly', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO']);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda?from=2026-07-20T00:00:00Z&to=2026-08-04T00:00:00Z')->json('data');

        expect(array_column($data, 'start'))->toBe(['2026-07-20T07:00:00Z', '2026-07-27T07:00:00Z', '2026-08-03T07:00:00Z'])
            ->and($data[0]['end'])->toBe('2026-07-20T08:00:00Z');
    });

    it('gives each occurrence its own stable id', function () {
        $user = agendaUser();
        $series = agendaEvent($user, '2026-07-20 08:00', '2026-07-20 09:00', ['rrule' => 'FREQ=DAILY;COUNT=3']);
        agendaAs($user);

        $ids = array_column($this->getJson('/api/v1/agenda?from=2026-07-20T00:00:00Z&to=2026-07-23T00:00:00Z')->json('data'), 'id');

        expect($ids)->toBe([
            "{$series->id}@2026-07-20T08:00:00Z",
            "{$series->id}@2026-07-21T08:00:00Z",
            "{$series->id}@2026-07-22T08:00:00Z",
        ]);
    });

    it('keeps a long running series that began more than two years ago', function () {
        $user = agendaUser();
        agendaEvent($user, '2023-01-02 08:00', '2023-01-02 09:00', ['title' => 'Daily', 'rrule' => 'FREQ=DAILY']);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda?from=2026-07-20T00:00:00Z&to=2026-07-22T00:00:00Z')->json('data');

        expect(array_column($data, 'start'))->toBe(['2026-07-20T07:00:00Z', '2026-07-21T07:00:00Z']);
    });

    it('includes an event that started before the range and is still running', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-07-20 09:00', '2026-07-20 11:00', ['title' => 'Running']);
        agendaEvent($user, '2026-07-20 08:00', '2026-07-20 10:00', ['title' => 'Ended at from']);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda?from=2026-07-20T10:00:00Z&to=2026-07-20T12:00:00Z')->json('data');

        expect(array_column($data, 'title'))->toBe(['Running']);
    });

    it('includes an occurrence that starts before from and is still running', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-07-13 09:00', '2026-07-13 11:00', ['title' => 'Series', 'rrule' => 'FREQ=WEEKLY']);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda?from=2026-07-20T10:00:00Z&to=2026-07-20T12:00:00Z')->json('data');

        expect(array_column($data, 'start'))->toBe(['2026-07-20T09:00:00Z']);
    });

    it('includes an occurrence that ends after to but starts before it', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-07-20 09:00', '2026-07-20 13:00', ['title' => 'Long']);
        agendaEvent($user, '2026-07-20 12:00', '2026-07-20 13:00', ['title' => 'Starts at to']);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda?from=2026-07-20T08:00:00Z&to=2026-07-20T12:00:00Z')->json('data');

        expect(array_column($data, 'title'))->toBe(['Long'])
            ->and($data[0]['end'])->toBe('2026-07-20T13:00:00Z');
    });

    it('keeps an all-day event as a floating date in every user timezone', function (string $timezone) {
        $user = agendaUser($timezone);
        agendaEvent($user, '2026-07-21 00:00', '2026-07-22 00:00', [
            'title' => 'Holiday', 'all_day' => true, 'timezone' => 'UTC',
        ]);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda?from=2026-07-20T00:00:00Z&to=2026-07-28T00:00:00Z')->json('data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['all_day'])->toBeTrue()
            ->and($data[0]['timezone'])->toBe('UTC')
            ->and($data[0]['start'])->toBe('2026-07-21T00:00:00Z')
            ->and($data[0]['end'])->toBe('2026-07-22T00:00:00Z')
            ->and($data[0]['url'])->toContain('date=2026-07-21');
    })->with(['Europe/Amsterdam', 'America/Los_Angeles', 'Pacific/Kiritimati', 'Pacific/Pago_Pago']);

    it('places an all-day event on the user local day when choosing what is today', function () {
        $user = agendaUser('Pacific/Kiritimati');
        agendaEvent($user, '2026-07-21 00:00', '2026-07-22 00:00', ['title' => 'Local today', 'all_day' => true, 'timezone' => 'UTC']);
        agendaEvent($user, '2026-07-20 00:00', '2026-07-21 00:00', ['title' => 'Local yesterday', 'all_day' => true, 'timezone' => 'UTC']);
        agendaAs($user);

        // 13:00 UTC on the 20th is already the 21st in Kiritimati (UTC+14).
        $response = $this->getJson('/api/v1/agenda')->assertOk();

        expect($response->json('meta.from'))->toBe('2026-07-20T10:00:00Z')
            ->and(array_column($response->json('data'), 'title'))->toBe(['Local today']);
    });

    it('does not show an all-day event from the day before the user today', function () {
        $user = agendaUser('Europe/Amsterdam');
        agendaEvent($user, '2026-07-19 00:00', '2026-07-20 00:00', ['title' => 'Yesterday', 'all_day' => true, 'timezone' => 'UTC']);
        agendaEvent($user, '2026-07-20 00:00', '2026-07-21 00:00', ['title' => 'Today', 'all_day' => true, 'timezone' => 'UTC']);
        agendaAs($user);

        expect(array_column($this->getJson('/api/v1/agenda')->json('data'), 'title'))->toBe(['Today']);
    });

    it('keeps a weekly 09:00 local series at 09:00 across the spring DST change', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-03-02 08:00', '2026-03-02 09:00', ['title' => 'Weekly', 'rrule' => 'FREQ=WEEKLY']);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda?from=2026-03-20T00:00:00Z&to=2026-04-07T00:00:00Z')->json('data');

        // Amsterdam switches to summer time on 2026-03-29.
        expect(array_column($data, 'start'))->toBe([
            '2026-03-23T08:00:00Z',
            '2026-03-30T07:00:00Z',
            '2026-04-06T07:00:00Z',
        ]);
    });

    it('spans a DST change with the default range', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-27 12:00:00', 'UTC'));
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda')
            ->assertOk()
            ->assertJsonPath('meta', ['from' => '2026-03-26T23:00:00Z', 'to' => '2026-04-02T22:00:00Z']);
    });

    it('follows an occurrence moved by an override and drops the original slot', function () {
        $user = agendaUser();
        $series = agendaEvent($user, '2026-07-20 08:00', '2026-07-20 09:00', ['title' => 'Series', 'rrule' => 'FREQ=WEEKLY']);
        agendaEvent($user, '2026-07-28 14:00', '2026-07-28 15:00', [
            'title' => 'Series (moved)',
            'overrides_event_id' => $series->id,
            'overrides_starts_at' => CarbonImmutable::parse('2026-07-27 08:00', 'UTC'),
        ]);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda?from=2026-07-20T00:00:00Z&to=2026-08-03T00:00:00Z')->json('data');

        expect(array_column($data, 'start'))->toBe(['2026-07-20T08:00:00Z', '2026-07-28T14:00:00Z'])
            ->and($data[1]['title'])->toBe('Series (moved)');
    });

    it('drops a cancelled occurrence', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-07-20 08:00', '2026-07-20 09:00', [
            'rrule' => 'FREQ=WEEKLY',
            'excluded_dates' => ['2026-07-27 08:00:00'],
        ]);
        agendaAs($user);

        $data = $this->getJson('/api/v1/agenda?from=2026-07-20T00:00:00Z&to=2026-08-04T00:00:00Z')->json('data');

        expect(array_column($data, 'start'))->toBe(['2026-07-20T08:00:00Z', '2026-08-03T08:00:00Z']);
    });

    it('leaves out soft-deleted events and series', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00', ['title' => 'Kept']);
        agendaEvent($user, '2026-07-21 10:00', '2026-07-21 11:00', ['title' => 'Deleted'])->delete();
        agendaEvent($user, '2026-07-21 12:00', '2026-07-21 13:00', ['title' => 'Deleted series', 'rrule' => 'FREQ=DAILY'])->delete();
        agendaAs($user);

        expect(array_column($this->getJson('/api/v1/agenda')->json('data'), 'title'))->toBe(['Kept']);
    });

    it('reports free events as is_free', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00', ['is_free' => true]);
        agendaEvent($user, '2026-07-21 10:00', '2026-07-21 11:00', ['is_free' => false]);
        agendaAs($user);

        expect(array_column($this->getJson('/api/v1/agenda')->json('data'), 'is_free'))->toBe([true, false]);
    });
});

describe('etag', function () {
    it('sends a strong ETag and answers a matching If-None-Match with 304', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00');
        agendaAs($user);

        $first = $this->getJson('/api/v1/agenda')->assertOk();
        $etag = $first->headers->get('ETag');

        expect($etag)->toMatch('/^"[0-9a-f]{64}"$/')
            ->and($etag)->toBe('"'.hash('sha256', $first->getContent()).'"');

        $second = $this->getJson('/api/v1/agenda', ['If-None-Match' => $etag]);
        $second->assertStatus(304);

        expect($second->getContent())->toBe('')
            ->and($second->headers->get('ETag'))->toBe($etag);
    });

    it('honors a list of validators and a weak prefix', function () {
        $user = agendaUser();
        agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00');
        agendaAs($user);

        $etag = $this->getJson('/api/v1/agenda')->headers->get('ETag');

        $this->getJson('/api/v1/agenda', ['If-None-Match' => '"other", '.$etag])->assertStatus(304);
        $this->getJson('/api/v1/agenda', ['If-None-Match' => 'W/'.$etag])->assertStatus(304);
        $this->getJson('/api/v1/agenda', ['If-None-Match' => '*'])->assertStatus(304);
    });

    it('sends the full body again when the validator is stale or the data changed', function () {
        $user = agendaUser();
        $event = agendaEvent($user, '2026-07-21 08:00', '2026-07-21 09:00', ['title' => 'Before']);
        agendaAs($user);

        $etag = $this->getJson('/api/v1/agenda')->headers->get('ETag');
        $event->update(['title' => 'After']);

        $changed = $this->getJson('/api/v1/agenda', ['If-None-Match' => $etag])->assertOk();

        expect($changed->headers->get('ETag'))->not->toBe($etag)
            ->and($changed->json('data.0.title'))->toBe('After');
        $this->getJson('/api/v1/agenda', ['If-None-Match' => '"nonsense"'])->assertOk();
    });

    it('gives different ranges different validators', function () {
        agendaAs(agendaUser());

        $a = $this->getJson('/api/v1/agenda?from=2026-07-20T00:00:00Z&to=2026-07-21T00:00:00Z')->headers->get('ETag');
        $b = $this->getJson('/api/v1/agenda?from=2026-07-20T00:00:00Z&to=2026-07-22T00:00:00Z')->headers->get('ETag');

        expect($a)->not->toBe($b);
    });

    it('does not answer 304 to a validator earned by another user', function () {
        $alice = agendaUser();
        $bob = agendaUser();
        agendaEvent($alice, '2026-07-21 08:00', '2026-07-21 09:00', ['title' => 'Alice only']);
        agendaAs($alice);
        $etag = $this->getJson('/api/v1/agenda')->headers->get('ETag');

        app('auth')->forgetGuards();
        agendaAs($bob);

        $this->getJson('/api/v1/agenda', ['If-None-Match' => $etag])->assertOk()->assertJsonPath('data', []);
    });

    it('does not send a 304 for an error response', function () {
        agendaAs(agendaUser());

        $this->getJson('/api/v1/agenda?from=garbage', ['If-None-Match' => '*'])->assertStatus(422);
    });
});
