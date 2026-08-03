<?php

use App\Actions\SyncConnectedAccountAction;
use App\Exceptions\ReauthorizationRequiredException;
use App\Jobs\SyncConnectedAccountJob;
use App\Models\ConnectedAccount;
use App\Services\Calendar\OAuthTokenRefresher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

it('treats a revoked Google refresh token as needing reauthorization', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], 400),
    ]);

    $account = ConnectedAccount::factory()->create([
        'provider' => ConnectedAccount::PROVIDER_GOOGLE,
        'oauth_refresh_token' => 'revoked',
        'oauth_expires_at' => now()->subMinutes(5),
    ]);

    expect(fn () => app(OAuthTokenRefresher::class)->freshAccessToken($account))
        ->toThrow(ReauthorizationRequiredException::class);
});

it('treats a revoked Microsoft refresh token as needing reauthorization', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $account = ConnectedAccount::factory()->microsoft()->create([
        'oauth_refresh_token' => 'revoked',
        'oauth_expires_at' => now()->subMinutes(5),
    ]);

    expect(fn () => app(OAuthTokenRefresher::class)->freshAccessToken($account))
        ->toThrow(ReauthorizationRequiredException::class);
});

it('leaves a transient token endpoint failure retryable', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response('upstream exploded', 503),
    ]);

    $account = ConnectedAccount::factory()->create([
        'provider' => ConnectedAccount::PROVIDER_GOOGLE,
        'oauth_refresh_token' => 'rtk',
        'oauth_expires_at' => now()->subMinutes(5),
    ]);

    try {
        app(OAuthTokenRefresher::class)->freshAccessToken($account);
        $this->fail('Expected the refresh to fail.');
    } catch (RuntimeException $e) {
        expect($e)->not->toBeInstanceOf(ReauthorizationRequiredException::class)
            ->and($e->getMessage())->toContain('503');
    }
});

it('deactivates an account whose grant is dead instead of failing the job', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $account = ConnectedAccount::factory()->create([
        'provider' => ConnectedAccount::PROVIDER_GOOGLE,
        'oauth_refresh_token' => 'revoked',
        'oauth_expires_at' => now()->subMinutes(5),
        'is_active' => true,
    ]);

    // No exception escapes, so the queue records no failure and never retries.
    (new SyncConnectedAccountJob($account))->handle(app(SyncConnectedAccountAction::class));

    $account->refresh();

    expect($account->is_active)->toBeFalse()
        ->and($account->sync_status)->toBe('error')
        ->and($account->sync_error)->toContain('Reconnect');
});

it('stops scheduling syncs for a deactivated account', function () {
    Queue::fake();

    ConnectedAccount::factory()->create(['is_active' => false]);

    $this->artisan('calendar:sync')->assertSuccessful();

    Queue::assertNothingPushed();
});
