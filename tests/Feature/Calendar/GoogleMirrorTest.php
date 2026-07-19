<?php

use App\Actions\SyncConnectedAccountAction;
use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\Event;
use Carbon\CarbonImmutable;
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

it('records the error and rethrows when the provider call fails', function () {
    Http::fake([
        '*/users/me/calendarList' => Http::response('nope', 500),
    ]);

    $account = googleAccount();

    expect(fn () => sync($account))->toThrow(RequestException::class);
    expect($account->fresh()->sync_status)->toBe('error');
});
