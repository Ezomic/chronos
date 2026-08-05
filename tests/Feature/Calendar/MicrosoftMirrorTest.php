<?php

use App\Actions\SyncConnectedAccountAction;
use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function microsoftAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->microsoft()->create([
        'oauth_access_token' => 'valid',
        'oauth_expires_at' => now()->addHour(),
    ]);
}

function graphCalendars(): array
{
    return ['value' => [
        ['id' => 'cal-1', 'name' => 'Calendar', 'hexColor' => '#8B5CF6'],
    ]];
}

function syncMs(ConnectedAccount $account): void
{
    app(SyncConnectedAccountAction::class)->handle($account->fresh());
}

it('mirrors Microsoft calendarView instances into read-only calendars', function () {
    $base = CarbonImmutable::now()->addDays(5);

    Http::fake([
        '*/me/calendars' => Http::response(graphCalendars()),
        '*/calendarView*' => Http::response(['value' => [
            [
                'id' => 'evt-1', '@odata.etag' => 'W/"1"', 'subject' => 'Sync',
                'isAllDay' => false,
                'start' => ['dateTime' => $base->format('Y-m-d').'T07:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => $base->format('Y-m-d').'T07:30:00.0000000', 'timeZone' => 'UTC'],
                'location' => ['displayName' => 'Room A'],
            ],
        ]]),
    ]);

    $account = microsoftAccount();
    syncMs($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();
    $event = Event::query()->where('external_id', 'evt-1')->firstOrFail();

    expect($calendar->name)->toBe('Calendar')
        ->and($calendar->is_writable)->toBeFalse()
        ->and($event->title)->toBe('Sync')
        ->and($event->location)->toBe('Room A')
        ->and($event->starts_at->utc()->format('Y-m-d H:i'))->toBe($base->format('Y-m-d').' 07:00')
        ->and($account->fresh()->sync_status)->toBe('idle');
});

it('reads events from calendarView, which expands recurrence', function () {
    $base = CarbonImmutable::now()->addDays(5);
    $instances = collect(range(0, 2))->map(fn (int $i) => [
        'id' => "rec-{$i}",
        'subject' => 'Weekly',
        'isAllDay' => false,
        'start' => ['dateTime' => $base->addDays($i)->format('Y-m-d').'T09:00:00.0000000', 'timeZone' => 'UTC'],
        'end' => ['dateTime' => $base->addDays($i)->format('Y-m-d').'T09:30:00.0000000', 'timeZone' => 'UTC'],
    ])->all();

    Http::fake([
        '*/me/calendars' => Http::response(graphCalendars()),
        '*/calendarView*' => Http::response(['value' => $instances]),
    ]);

    $account = microsoftAccount();
    syncMs($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();
    expect(Event::where('calendar_id', $calendar->id)->count())->toBe(3);
});

it('requests a local timezone and stores mirrored events in it', function () {
    config()->set('services.microsoft.timezone', 'Europe/Amsterdam');
    $base = CarbonImmutable::now()->addDays(5);

    Http::fake([
        '*/me/calendars' => Http::response(graphCalendars()),
        '*/calendarView*' => Http::response(['value' => [
            [
                'id' => 'tz-1', 'subject' => 'Local time',
                'isAllDay' => false,
                // Graph echoes the requested IANA zone and returns times in it.
                'start' => ['dateTime' => $base->format('Y-m-d').'T09:00:00.0000000', 'timeZone' => 'Europe/Amsterdam'],
                'end' => ['dateTime' => $base->format('Y-m-d').'T09:30:00.0000000', 'timeZone' => 'Europe/Amsterdam'],
            ],
        ]]),
    ]);

    syncMs(microsoftAccount());

    $event = Event::query()->where('external_id', 'tz-1')->firstOrFail();
    expect($event->timezone)->toBe('Europe/Amsterdam')
        // 09:00 Amsterdam (+02:00) is 07:00 UTC.
        ->and($event->starts_at->utc()->format('H:i'))->toBe('07:00');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'calendarView')
        && $request->header('Prefer')[0] === 'outlook.timezone="Europe/Amsterdam"');
});

it('follows @odata.nextLink so every page of events is mirrored', function () {
    $base = CarbonImmutable::now()->addDays(5);

    $instance = fn (string $id, int $offset): array => [
        'id' => $id,
        'subject' => 'Paged',
        'isAllDay' => false,
        'start' => ['dateTime' => $base->addDays($offset)->format('Y-m-d').'T09:00:00.0000000', 'timeZone' => 'UTC'],
        'end' => ['dateTime' => $base->addDays($offset)->format('Y-m-d').'T09:30:00.0000000', 'timeZone' => 'UTC'],
    ];

    Http::fake([
        '*/me/calendars' => Http::response(graphCalendars()),
        '*/calendarView*' => Http::sequence()
            ->push([
                'value' => [$instance('evt-1', 0), $instance('evt-2', 1)],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars/cal-1/calendarView?$skiptoken=page2',
            ])
            ->push(['value' => [$instance('evt-3', 2)]]),
    ]);

    $account = microsoftAccount();
    syncMs($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();
    expect(Event::where('calendar_id', $calendar->id)->pluck('external_id')->sort()->values()->all())
        ->toBe(['evt-1', 'evt-2', 'evt-3']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'skiptoken=page2'));
});

it('keeps mirrored Microsoft events when a later page fails mid-sync', function () {
    $base = CarbonImmutable::now()->addDays(5);

    $page = ['value' => [[
        'id' => 'evt-1', 'subject' => 'Paged', 'isAllDay' => false,
        'start' => ['dateTime' => $base->format('Y-m-d').'T09:00:00.0000000', 'timeZone' => 'UTC'],
        'end' => ['dateTime' => $base->format('Y-m-d').'T09:30:00.0000000', 'timeZone' => 'UTC'],
    ]]];

    Http::fake([
        '*/me/calendars' => Http::response(graphCalendars()),
        '*/calendarView*' => Http::sequence()
            ->push($page)
            ->push($page + ['@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars/cal-1/calendarView?$skiptoken=page2'])
            ->push('upstream exploded', 500),
    ]);

    $account = microsoftAccount();
    syncMs($account);

    expect(fn () => syncMs($account))->toThrow(RequestException::class);
    expect(Event::query()->pluck('external_id')->all())->toBe(['evt-1']);
});

it('maps an all-day Microsoft event to a midnight-UTC span', function () {
    $day = CarbonImmutable::now()->addDays(5);

    Http::fake([
        '*/me/calendars' => Http::response(graphCalendars()),
        '*/calendarView*' => Http::response(['value' => [
            [
                'id' => 'allday-1', 'subject' => 'Off',
                'isAllDay' => true,
                'start' => ['dateTime' => $day->format('Y-m-d').'T00:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => $day->addDay()->format('Y-m-d').'T00:00:00.0000000', 'timeZone' => 'UTC'],
            ],
        ]]),
    ]);

    $account = microsoftAccount();
    syncMs($account);

    $event = Event::query()->where('external_id', 'allday-1')->firstOrFail();
    expect($event->all_day)->toBeTrue()
        ->and($event->timezone)->toBe('UTC')
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe($day->format('Y-m-d').' 00:00')
        ->and($event->ends_at->format('Y-m-d H:i'))->toBe($day->addDay()->format('Y-m-d').' 00:00');
});
