<?php

use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('wires up the calendar relationships', function () {
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create();
    $calendar = Calendar::factory()->for($user)->for($account, 'connectedAccount')->create();
    $event = Event::factory()->for($calendar)->create();

    expect($calendar->user->is($user))->toBeTrue()
        ->and($calendar->connectedAccount->is($account))->toBeTrue()
        ->and($calendar->events->first()->is($event))->toBeTrue()
        ->and($event->calendar->is($calendar))->toBeTrue()
        ->and($account->calendars->first()->is($calendar))->toBeTrue();
});

it('encrypts connected-account tokens at rest but reads them back plainly', function () {
    $account = ConnectedAccount::factory()->create([
        'oauth_access_token' => 'plain-access',
        'oauth_refresh_token' => 'plain-refresh',
    ]);

    expect($account->fresh()->oauth_access_token)->toBe('plain-access')
        ->and($account->fresh()->oauth_refresh_token)->toBe('plain-refresh');

    $raw = DB::table('connected_accounts')->where('id', $account->id)->first();
    expect($raw->oauth_access_token)->not->toBe('plain-access')
        ->and($raw->oauth_refresh_token)->not->toBe('plain-refresh');
});

it('casts event timestamps to immutable dates', function () {
    $event = Event::factory()->create();

    expect($event->starts_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->all_day)->toBeFalse();
});

it('stores an all-day event as an exclusive midnight-UTC span', function () {
    $event = Event::factory()->allDay()->create();

    expect($event->all_day)->toBeTrue()
        ->and($event->timezone)->toBe('UTC')
        ->and($event->starts_at->format('H:i:s'))->toBe('00:00:00')
        ->and($event->ends_at->format('H:i:s'))->toBe('00:00:00')
        ->and($event->starts_at->addDay()->equalTo($event->ends_at))->toBeTrue();
});

it('records the originating email on an event created from mail', function () {
    $event = Event::factory()->fromEmail()->create();

    expect($event->source_app)->toBe('zero')
        ->and($event->source_type)->toBe('email')
        ->and($event->source_url)->toStartWith('https://zero.test/emails/ref/')
        ->and($event->source_url)->toEndWith($event->source_id);
});
