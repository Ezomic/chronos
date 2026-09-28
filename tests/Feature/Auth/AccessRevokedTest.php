<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Auth\User as FrameworkUser;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

/**
 * id-client only ends the web session when ID revokes a user's access, so the
 * API tokens they had minted kept working for as long as they existed.
 *
 * @return array{User, string}
 */
function ssoUserWithToken(string $idpId): array
{
    $user = User::factory()->create();
    $user->forceFill(['idp_id' => $idpId])->save();

    return [$user, $user->createToken('Zero', ['events:create', 'events:manage', 'app:zero'])->plainTextToken];
}

function sendIdEvent(string $event, User $user): TestResponse
{
    config(['id-client.logout_secret' => 'test-logout-secret']);

    $body = (string) json_encode(['event' => $event, 'sub' => $user->idp_id, 'issued_at' => now()->getTimestamp()]);

    return test()->call('POST', route('sso.logout'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ID_SIGNATURE' => hash_hmac('sha256', $body, 'test-logout-secret'),
    ], content: $body);
}

it('deletes every API token of the user when ID revokes their access', function () {
    [$user, $plain] = ssoUserWithToken('idp-1');
    $user->createToken('Tempo', ['events:create', 'app:tempo']);
    [$someoneElse] = ssoUserWithToken('idp-2');

    $this->withToken($plain)->getJson('/api/v1/events')->assertOk();

    sendIdEvent('access.revoked', $user)->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/events')->assertUnauthorized();

    expect($user->tokens()->count())->toBe(0)
        ->and($someoneElse->tokens()->count())->toBe(1);
});

/**
 * ID sends a logout per session, so signing out on one machine must not stop
 * zero or tempo creating events. Only a lost grant should.
 */
it('keeps the tokens on a plain ID logout', function () {
    [$user, $plain] = ssoUserWithToken('idp-1');

    sendIdEvent('logout', $user)->assertOk();

    $this->withToken($plain)->getJson('/api/v1/events')->assertOk();
});

/**
 * A 500 alone proves nothing: calling tokens() on the framework user throws a
 * BadMethodCallException, which is a 500 and a LogicException too. So the test
 * pins the listener's own exception by its message.
 */
it('fails the delivery, so ID retries it, when id-client is not pointed at the app user model', function () {
    Exceptions::fake();
    [$user] = ssoUserWithToken('idp-1');
    config(['id-client.user_model' => FrameworkUser::class]);

    sendIdEvent('access.revoked', $user)->assertServerError();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (LogicException $e): bool => $e->getMessage() === 'id-client.user_model is not '.User::class.'.');
    expect($user->tokens()->count())->toBe(1);
});
