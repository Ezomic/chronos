<?php

use App\Models\ConnectedAccount;
use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeOAuthUser(string $email, ?string $refreshToken = 'rtk'): SocialiteUser
{
    $user = (new SocialiteUser)->map(['name' => 'Robbin', 'email' => $email]);
    $user->token = 'atk';
    $user->refreshToken = $refreshToken;
    $user->expiresIn = 3600;

    return $user;
}

function mockSocialiteUser(string $provider, SocialiteUser $user): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

it('connects a Google account from the callback', function () {
    $user = User::factory()->create();
    mockSocialiteUser('google', fakeOAuthUser('me@gmail.com'));

    $this->actingAs($user)
        ->get(route('oauth.callback', ['provider' => 'google']))
        ->assertRedirect(route('calendars.edit'));

    $account = ConnectedAccount::query()->firstOrFail();
    expect($account->provider)->toBe('google')
        ->and($account->email_address)->toBe('me@gmail.com')
        ->and($account->oauth_access_token)->toBe('atk')
        ->and($account->oauth_refresh_token)->toBe('rtk');
});

it('keeps the existing refresh token when a re-consent omits one', function () {
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create([
        'provider' => 'google',
        'email_address' => 'me@gmail.com',
        'oauth_refresh_token' => 'original-rtk',
    ]);

    mockSocialiteUser('google', fakeOAuthUser('me@gmail.com', refreshToken: null));

    $this->actingAs($user)->get(route('oauth.callback', ['provider' => 'google']));

    expect($account->fresh()->oauth_refresh_token)->toBe('original-rtk')
        ->and(ConnectedAccount::count())->toBe(1);
});

it('404s an unknown provider', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('oauth.redirect', ['provider' => 'dropbox']))
        ->assertNotFound();
});

it('disconnects an account', function () {
    $user = User::factory()->create();
    $account = ConnectedAccount::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('connected-accounts.destroy', $account))
        ->assertRedirect();

    expect(ConnectedAccount::find($account->id))->toBeNull();
});

it('forbids disconnecting another user\'s account', function () {
    $account = ConnectedAccount::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('connected-accounts.destroy', $account))
        ->assertForbidden();

    expect(ConnectedAccount::find($account->id))->not->toBeNull();
});

it('renders the connected-calendars settings page', function () {
    $user = User::factory()->create();
    ConnectedAccount::factory()->for($user)->create(['email_address' => 'me@gmail.com']);

    $this->actingAs($user)
        ->get(route('calendars.edit'))
        ->assertOk();
});
