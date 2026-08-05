<?php

use App\Actions\SyncConnectedAccountAction;
use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function googleAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'provider' => ConnectedAccount::PROVIDER_GOOGLE,
        'oauth_access_token' => 'valid',
        'oauth_expires_at' => now()->addHour(),
    ]);
}

function calendarListResponse(): array
{
    return [
        'items' => [
            ['id' => 'primary', 'summary' => 'Work', 'backgroundColor' => '#10B981', 'timeZone' => 'Europe/Amsterdam'],
        ],
    ];
}

function timedInstance(string $id, string $day): array
{
    return [
        'id' => $id,
        'etag' => '"etag-'.$id.'"',
        'summary' => 'Standup',
        'start' => ['dateTime' => "{$day}T09:00:00+02:00", 'timeZone' => 'Europe/Amsterdam'],
        'end' => ['dateTime' => "{$day}T09:15:00+02:00"],
    ];
}

function sync(ConnectedAccount $account): void
{
    app(SyncConnectedAccountAction::class)->handle($account->fresh());
}

it('mirrors a calendar and its expanded recurrence instances', function () {
    $base = CarbonImmutable::now()->addDays(5);

    Http::fake([
        '*/users/me/calendarList' => Http::response(calendarListResponse()),
        '*/events*' => Http::response(['items' => [
            timedInstance('rec_1', $base->format('Y-m-d')),
            timedInstance('rec_2', $base->addDay()->format('Y-m-d')),
            timedInstance('rec_3', $base->addDays(2)->format('Y-m-d')),
        ]]),
    ]);

    $account = googleAccount();
    sync($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();
    expect($calendar->name)->toBe('Work')
        ->and($calendar->is_writable)->toBeFalse()
        ->and(Event::where('calendar_id', $calendar->id)->count())->toBe(3)
        ->and($account->fresh()->sync_status)->toBe('idle')
        ->and($account->fresh()->last_synced_at)->not->toBeNull();
});

it('is idempotent: re-syncing the same events makes no duplicates', function () {
    $base = CarbonImmutable::now()->addDays(5);

    Http::fake([
        '*/users/me/calendarList' => Http::response(calendarListResponse()),
        '*/events*' => Http::response(['items' => [
            timedInstance('rec_1', $base->format('Y-m-d')),
            timedInstance('rec_2', $base->addDay()->format('Y-m-d')),
        ]]),
    ]);

    $account = googleAccount();
    sync($account);
    sync($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();
    expect(Event::where('calendar_id', $calendar->id)->count())->toBe(2);
});

it('prunes an event removed upstream within the window', function () {
    $base = CarbonImmutable::now()->addDays(5);

    Http::fake([
        '*/users/me/calendarList' => Http::response(calendarListResponse()),
        '*/events*' => Http::sequence()
            ->push(['items' => [
                timedInstance('rec_1', $base->format('Y-m-d')),
                timedInstance('rec_2', $base->addDay()->format('Y-m-d')),
            ]])
            ->push(['items' => [
                timedInstance('rec_1', $base->format('Y-m-d')),
            ]]),
    ]);

    $account = googleAccount();
    sync($account);
    sync($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();
    expect(Event::where('calendar_id', $calendar->id)->pluck('external_id')->all())->toBe(['rec_1']);
});

it('maps an all-day Google event to a midnight-UTC span', function () {
    $day = CarbonImmutable::now()->addDays(5)->format('Y-m-d');
    $next = CarbonImmutable::now()->addDays(6)->format('Y-m-d');

    Http::fake([
        '*/users/me/calendarList' => Http::response(calendarListResponse()),
        '*/events*' => Http::response(['items' => [
            ['id' => 'allday_1', 'summary' => 'Holiday', 'start' => ['date' => $day], 'end' => ['date' => $next]],
        ]]),
    ]);

    $account = googleAccount();
    sync($account);

    $event = Event::query()->where('external_id', 'allday_1')->firstOrFail();
    expect($event->all_day)->toBeTrue()
        ->and($event->timezone)->toBe('UTC')
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe("{$day} 00:00");
});

it('follows nextPageToken so every page of events is mirrored', function () {
    $base = CarbonImmutable::now()->addDays(5);

    Http::fake([
        '*/users/me/calendarList' => Http::response(calendarListResponse()),
        '*/events*' => Http::sequence()
            ->push([
                'items' => [
                    timedInstance('page1_a', $base->format('Y-m-d')),
                    timedInstance('page1_b', $base->addDay()->format('Y-m-d')),
                ],
                'nextPageToken' => 'token-2',
            ])
            ->push(['items' => [
                timedInstance('page2_a', $base->addDays(2)->format('Y-m-d')),
            ]]),
    ]);

    $account = googleAccount();
    sync($account);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();
    expect(Event::where('calendar_id', $calendar->id)->pluck('external_id')->sort()->values()->all())
        ->toBe(['page1_a', 'page1_b', 'page2_a']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pageToken=token-2'));
});

it('pages the calendar list too', function () {
    Http::fake([
        '*/users/me/calendarList*' => Http::sequence()
            ->push([
                'items' => [['id' => 'first', 'summary' => 'First', 'timeZone' => 'UTC']],
                'nextPageToken' => 'token-2',
            ])
            ->push(['items' => [['id' => 'second', 'summary' => 'Second', 'timeZone' => 'UTC']]]),
        '*/events*' => Http::response(['items' => []]),
    ]);

    $account = googleAccount();
    sync($account);

    expect(Calendar::query()->where('connected_account_id', $account->id)->pluck('external_id')->sort()->values()->all())
        ->toBe(['first', 'second']);
});

it('keeps mirrored events when a later page fails mid-sync', function () {
    $base = CarbonImmutable::now()->addDays(5);

    $firstPage = [
        timedInstance('rec_1', $base->format('Y-m-d')),
        timedInstance('rec_2', $base->addDay()->format('Y-m-d')),
    ];

    Http::fake([
        '*/users/me/calendarList' => Http::response(calendarListResponse()),
        '*/events*' => Http::sequence()
            // A complete first sync, then a sync whose second page blows up.
            ->push(['items' => $firstPage])
            ->push(['items' => $firstPage, 'nextPageToken' => 'token-2'])
            ->push('upstream exploded', 500),
    ]);

    $account = googleAccount();
    sync($account);

    expect(fn () => sync($account))->toThrow(RequestException::class);

    $calendar = Calendar::query()->where('connected_account_id', $account->id)->firstOrFail();
    expect(Event::where('calendar_id', $calendar->id)->pluck('external_id')->sort()->values()->all())
        ->toBe(['rec_1', 'rec_2']);
});

it('refuses a partial sync when pagination never terminates', function () {
    $base = CarbonImmutable::now()->addDays(5);

    Http::fake([
        '*/users/me/calendarList' => Http::response(calendarListResponse()),
        // A provider that always claims another page: better to fail than to
        // prune against a set we know is incomplete.
        '*/events*' => Http::response([
            'items' => [timedInstance('rec_1', $base->format('Y-m-d'))],
            'nextPageToken' => 'always-more',
        ]),
    ]);

    $account = googleAccount();

    expect(fn () => sync($account))->toThrow(RuntimeException::class);
    expect(Event::query()->count())->toBe(0)
        ->and($account->fresh()->sync_status)->toBe('error');
});

it('records the error and rethrows when the provider call fails', function () {
    Http::fake([
        '*/users/me/calendarList' => Http::response('nope', 500),
    ]);

    $account = googleAccount();

    expect(fn () => sync($account))->toThrow(RequestException::class);
    expect($account->fresh()->sync_status)->toBe('error');
});
