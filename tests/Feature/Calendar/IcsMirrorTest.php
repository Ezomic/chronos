<?php

use App\Actions\SyncConnectedAccountAction;
use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

const ICS_FEED_URL = 'https://feeds.test/calendar.ics';

function icsAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'provider' => ConnectedAccount::PROVIDER_ICS,
        'email_address' => null,
        'feed_url' => ICS_FEED_URL,
        'feed_url_hash' => hash('sha256', ICS_FEED_URL),
        'oauth_access_token' => null,
        'oauth_refresh_token' => null,
        'oauth_expires_at' => null,
    ]);
}

function icsDocument(string $body): string
{
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Test//EN',
        'X-WR-CALNAME:FC Twente',
        $body,
        'END:VCALENDAR',
    ];

    return implode("\r\n", $lines)."\r\n";
}

function fakeIcsFeed(string $body): void
{
    Http::fake(['feeds.test/*' => Http::response(icsDocument($body))]);
}

function syncIcs(ConnectedAccount $account): void
{
    app(SyncConnectedAccountAction::class)->handle($account->fresh());
}

it('mirrors a timed ICS event into a read-only calendar named from the feed', function () {
    $start = CarbonImmutable::now('UTC')->addDays(5)->setTime(18, 45);
    $end = $start->addMinutes(105);

    fakeIcsFeed(implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:match-1@twente',
        'DTSTART:'.$start->format('Ymd\THis\Z'),
        'DTEND:'.$end->format('Ymd\THis\Z'),
        'SUMMARY:FC Twente - Telstar',
        'LOCATION:De Grolsch Veste',
        'END:VEVENT',
    ]));

    $account = icsAccount();
    syncIcs($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();
    $event = Event::query()->where('external_id', 'match-1@twente')->firstOrFail();

    expect($calendar->name)->toBe('FC Twente')
        ->and($calendar->is_writable)->toBeFalse()
        ->and($event->title)->toBe('FC Twente - Telstar')
        ->and($event->location)->toBe('De Grolsch Veste')
        ->and($event->all_day)->toBeFalse()
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe($start->format('Y-m-d H:i'))
        ->and($account->fresh()->sync_status)->toBe('idle')
        ->and($account->fresh()->last_synced_at)->not->toBeNull();
});

it('converts a zoned ICS event to UTC and keeps its timezone name', function () {
    $day = CarbonImmutable::now('UTC')->addDays(6)->format('Ymd');

    fakeIcsFeed(implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:zoned-1',
        'DTSTART;TZID=Europe/Amsterdam:'.$day.'T203000',
        'DTEND;TZID=Europe/Amsterdam:'.$day.'T221500',
        'SUMMARY:Avondwedstrijd',
        'END:VEVENT',
    ]));

    $account = icsAccount();
    syncIcs($account);

    $event = Event::query()->where('external_id', 'zoned-1')->firstOrFail();

    // 20:30 Amsterdam (summer, UTC+2) stored as 18:30 UTC.
    expect($event->starts_at->format('H:i'))->toBe('18:30')
        ->and($event->timezone)->toBe('Europe/Amsterdam');
});

it('maps an all-day ICS event to a midnight-UTC span', function () {
    $day = CarbonImmutable::now('UTC')->addDays(7);

    fakeIcsFeed(implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:allday-1',
        'DTSTART;VALUE=DATE:'.$day->format('Ymd'),
        'DTEND;VALUE=DATE:'.$day->addDay()->format('Ymd'),
        'SUMMARY:Rustdag',
        'END:VEVENT',
    ]));

    $account = icsAccount();
    syncIcs($account);

    $event = Event::query()->where('external_id', 'allday-1')->firstOrFail();

    expect($event->all_day)->toBeTrue()
        ->and($event->timezone)->toBe('UTC')
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe($day->format('Y-m-d').' 00:00');
});

it('unfolds folded lines and unescapes text values', function () {
    $start = CarbonImmutable::now('UTC')->addDays(8)->setTime(14, 30);

    // A summary folded across two physical lines with an escaped comma.
    fakeIcsFeed(implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:folded-1',
        'DTSTART:'.$start->format('Ymd\THis\Z'),
        'SUMMARY:FC Twente - Ajax\, de top',
        ' per in De Grolsch Veste',
        'END:VEVENT',
    ]));

    $account = icsAccount();
    syncIcs($account);

    $event = Event::query()->where('external_id', 'folded-1')->firstOrFail();

    expect($event->title)->toBe('FC Twente - Ajax, de topper in De Grolsch Veste');
});

it('expands a recurring ICS event into instances within the window', function () {
    $first = CarbonImmutable::now('UTC')->addDays(3)->setTime(20, 0);

    fakeIcsFeed(implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:weekly-1',
        'DTSTART:'.$first->format('Ymd\THis\Z'),
        'DTEND:'.$first->addHour()->format('Ymd\THis\Z'),
        'RRULE:FREQ=WEEKLY;COUNT=3',
        'SUMMARY:Training',
        'END:VEVENT',
    ]));

    $account = icsAccount();
    syncIcs($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();

    expect(Event::where('calendar_id', $calendar->id)->count())->toBe(3);
});

it('is idempotent: re-syncing a feed makes no duplicates', function () {
    $start = CarbonImmutable::now('UTC')->addDays(5)->setTime(16, 45);

    fakeIcsFeed(implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:dedup-1',
        'DTSTART:'.$start->format('Ymd\THis\Z'),
        'SUMMARY:Uitwedstrijd',
        'END:VEVENT',
    ]));

    $account = icsAccount();
    syncIcs($account);
    syncIcs($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();

    expect(Event::where('calendar_id', $calendar->id)->count())->toBe(1);
});

it('records the error and rethrows when the feed is unreachable', function () {
    Http::fake(['feeds.test/*' => Http::response('nope', 500)]);

    $account = icsAccount();

    expect(fn () => syncIcs($account))->toThrow(RequestException::class);
    expect($account->fresh()->sync_status)->toBe('error');
});
