<?php

use App\Models\ConnectedAccount;
use App\Services\Calendar\OAuthTokenRefresher;
use Illuminate\Support\Facades\Http;

it('returns the cached token when it is still valid', function () {
    Http::fake();

    $account = ConnectedAccount::factory()->create([
        'oauth_access_token' => 'still-good',
        'oauth_expires_at' => now()->addHour(),
    ]);

    expect(app(OAuthTokenRefresher::class)->freshAccessToken($account))->toBe('still-good');
    Http::assertNothingSent();
});

it('refreshes an expired Google token', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'fresh-google',
            'expires_in' => 3600,
        ]),
    ]);

    $account = ConnectedAccount::factory()->create([
        'provider' => ConnectedAccount::PROVIDER_GOOGLE,
        'oauth_access_token' => 'stale',
        'oauth_refresh_token' => 'rtk',
        'oauth_expires_at' => now()->subMinutes(5),
    ]);

    expect(app(OAuthTokenRefresher::class)->freshAccessToken($account))->toBe('fresh-google')
        ->and($account->fresh()->oauth_access_token)->toBe('fresh-google');
});

it('persists Microsoft\'s rotated refresh token', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fresh-ms',
            'refresh_token' => 'rotated-rtk',
            'expires_in' => 3600,
        ]),
    ]);

    $account = ConnectedAccount::factory()->microsoft()->create([
        'oauth_access_token' => 'stale',
        'oauth_refresh_token' => 'old-rtk',
        'oauth_expires_at' => now()->subMinutes(5),
    ]);

    app(OAuthTokenRefresher::class)->freshAccessToken($account);

    expect($account->fresh()->oauth_access_token)->toBe('fresh-ms')
        ->and($account->fresh()->oauth_refresh_token)->toBe('rotated-rtk');
});
