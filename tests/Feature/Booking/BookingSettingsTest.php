<?php

use App\Actions\SyncConnectedAccountAction;
use App\Models\ConnectedAccount;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->owner = bookingOwner();
    atMondayMorning();
});

function week(array $overrides = []): array
{
    $days = [];

    foreach (range(1, 7) as $weekday) {
        $days[] = ['weekday' => $weekday, 'enabled' => $weekday <= 5, 'start' => '09:00', 'end' => '17:00'];
    }

    foreach ($overrides as $weekday => $values) {
        $days[$weekday - 1] = [...$days[$weekday - 1], ...$values];
    }

    return ['days' => $days];
}

it('sends guests to log in', function () {
    $this->get(route('booking-settings.edit'))->assertRedirect(route('login'));
    $this->put(route('booking-settings.update'), week())->assertRedirect(route('login'));
});

it('shows the default Monday to Friday hours and the public link', function () {
    $this->actingAs($this->owner)->get(route('booking-settings.edit'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/Booking')
        ->where('timezone', 'Europe/Amsterdam')
        ->where('days.0', ['weekday' => 1, 'enabled' => true, 'start' => '09:00', 'end' => '17:00'])
        ->where('days.5.enabled', false)
        ->where('url', url('/book')));
});

it('changes the weekly hours the public page offers', function () {
    $this->actingAs($this->owner)
        ->put(route('booking-settings.update'), week([3 => ['enabled' => false], 6 => ['enabled' => true, 'start' => '10:00', 'end' => '12:00']]))
        ->assertRedirect();

    auth()->logout();

    $slots = bookableSlots()->map(fn ($s) => $s->setTimezone('Europe/Amsterdam')->format('D H:i'));

    expect($slots)->not->toContain('Wed 09:00')
        ->and($slots)->toContain('Sat 10:00', 'Sat 11:30')
        ->and($slots)->not->toContain('Sat 12:00');
});

it('rejects hours that make no sense', function (array $override, string $field) {
    $this->actingAs($this->owner)
        ->put(route('booking-settings.update'), week([1 => $override]))
        ->assertSessionHasErrors($field);
})->with([
    'end before start' => [['start' => '17:00', 'end' => '09:00'], 'days.0.end'],
    'end equals start' => [['start' => '09:00', 'end' => '09:00'], 'days.0.end'],
    'not a time' => [['start' => '9am'], 'days.0.start'],
    'out of range' => [['end' => '25:00'], 'days.0.end'],
    'shorter than a call' => [['start' => '09:00', 'end' => '09:10'], 'days.0.end'],
]);

it('rejects a week with a missing or extra weekday', function () {
    $short = week();
    array_pop($short['days']);

    $this->actingAs($this->owner)->put(route('booking-settings.update'), $short)->assertSessionHasErrors('days');
    $this->actingAs($this->owner)->put(route('booking-settings.update'), week([1 => ['weekday' => 9]]))->assertSessionHasErrors('days.0.weekday');
});

it('marks mirrored Google events free when Google says they are transparent', function () {
    $account = ConnectedAccount::factory()->create([
        'provider' => ConnectedAccount::PROVIDER_GOOGLE,
        'oauth_access_token' => 'valid',
        'oauth_expires_at' => now()->addHour(),
    ]);

    $day = CarbonImmutable::now()->addDays(5)->format('Y-m-d');
    $instance = fn (string $id, array $extra) => [
        'id' => $id,
        'etag' => '"e"',
        'summary' => 'Thing',
        'start' => ['dateTime' => "{$day}T09:00:00+02:00", 'timeZone' => 'Europe/Amsterdam'],
        'end' => ['dateTime' => "{$day}T10:00:00+02:00"],
        ...$extra,
    ];

    Http::fake([
        '*/users/me/calendarList' => Http::response(['items' => [['id' => 'primary', 'summary' => 'Work', 'timeZone' => 'Europe/Amsterdam']]]),
        '*/events*' => Http::response(['items' => [
            $instance('busy', []),
            $instance('free', ['transparency' => 'transparent']),
            $instance('opaque', ['transparency' => 'opaque']),
        ]]),
    ]);

    app(SyncConnectedAccountAction::class)->handle($account->fresh());

    expect(Event::where('external_id', 'busy')->value('is_free'))->toBeFalse()
        ->and(Event::where('external_id', 'free')->value('is_free'))->toBeTrue()
        ->and(Event::where('external_id', 'opaque')->value('is_free'))->toBeFalse();

    $account->refresh();
    app(SyncConnectedAccountAction::class)->handle($account->fresh());
    expect(Event::where('external_id', 'free')->value('is_free'))->toBeTrue();
});
