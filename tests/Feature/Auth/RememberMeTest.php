<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Signs in through a faked ID and hands back the remember-me cookie exactly as
 * the browser received it.
 *
 * @return array{string, string}
 */
function signInThroughId(): array
{
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn((new SocialiteUser)->setRaw([
        'sub' => '42',
        'name' => 'Robbin Thijssen',
        'email' => 'robbin@example.com',
        'applications' => ['chronos'],
    ])->map([
        'id' => '42',
        'name' => 'Robbin Thijssen',
        'email' => 'robbin@example.com',
    ]));
    Socialite::shouldReceive('driver')->with('thijssensoftware')->andReturn($provider);

    $name = Auth::guard('web')->getRecallerName();
    $cookie = test()->get(route('sso.callback'))->assertRedirect('/dashboard')->getCookie($name, decrypt: false);

    expect($cookie)->not->toBeNull();

    return [$name, (string) $cookie?->getValue()];
}

/**
 * What the browser sends once its session has expired: no session, only the
 * remember-me cookie.
 */
function returnWithOnlyTheRememberCookie(string $name, string $value): TestResponse
{
    session()->flush();
    Auth::forgetGuards();

    return test()->withUnencryptedCookie($name, $value)->get(route('dashboard'));
}

function signOutAtId(User $user): TestResponse
{
    config(['id-client.logout_secret' => 'test-logout-secret']);

    $body = (string) json_encode(['event' => 'logout', 'sub' => $user->idp_id, 'issued_at' => now()->getTimestamp()]);

    return test()->call('POST', route('sso.logout'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ID_SIGNATURE' => hash_hmac('sha256', $body, 'test-logout-secret'),
    ], content: $body);
}

it('keeps a browser signed in after its session expires', function () {
    [$name, $value] = signInThroughId();

    returnWithOnlyTheRememberCookie($name, $value)->assertOk();

    $this->assertAuthenticatedAs(User::where('email', 'robbin@example.com')->firstOrFail());
});

it('refuses the remember-me cookie once ID signs the user out', function () {
    [$name, $value] = signInThroughId();
    returnWithOnlyTheRememberCookie($name, $value)->assertOk();

    signOutAtId(User::where('email', 'robbin@example.com')->firstOrFail())->assertOk();

    returnWithOnlyTheRememberCookie($name, $value)->assertRedirect(route('login'));
    $this->assertGuest();
});
