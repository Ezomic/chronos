<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

it('mints an events:create token by default', function () {
    $user = User::factory()->create();

    $this->artisan('calendar:token', ['email' => $user->email])->assertSuccessful();

    expect(PersonalAccessToken::query()->firstOrFail()->abilities)->toBe(['events:create']);
});

it('mints a token with several abilities and an app scope', function () {
    $user = User::factory()->create();

    $this->artisan('calendar:token', [
        'email' => $user->email,
        '--ability' => ['events:create', 'events:manage'],
        '--app' => 'zero',
    ])->assertSuccessful();

    expect(PersonalAccessToken::query()->firstOrFail()->abilities)
        ->toBe(['events:create', 'events:manage', 'app:zero']);
});

it('fails for an unknown email', function () {
    $this->artisan('calendar:token', ['email' => 'nobody@example.com'])->assertFailed();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});
