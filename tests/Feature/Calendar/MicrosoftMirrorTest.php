<?php

use App\Actions\SyncConnectedAccountAction;
use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\Event;
use Carbon\CarbonImmutable;
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
