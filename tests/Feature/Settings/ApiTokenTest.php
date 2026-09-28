<?php

declare(strict_types=1);

use App\Actions\CreateApiTokenAction;
use App\Enums\ApiAbility;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

function tokenOwner(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    actingAs($user);

    return $user;
}

/**
 * The API is called with no session at all. auth:sanctum falls back to the web
 * guard, so a lingering session would authenticate the request and the test
 * would pass without the token doing anything.
 */
function asFreshApiClient(): void
{
    test()->flushSession();
    app('auth')->forgetGuards();
}

/**
 * @return array<string, mixed>
 */
function newEventPayload(): array
{
    return [
        'title' => 'Reply to Acme',
        'starts_at' => '2026-07-20T09:00:00+02:00',
        'ends_at' => '2026-07-20T09:30:00+02:00',
        'timezone' => 'Europe/Amsterdam',
    ];
}

it('requires authentication', function () {
    $this->get('/settings/api-tokens')->assertRedirect('/login');
});

it('lists the acting user’s tokens and nobody else’s', function () {
    $user = tokenOwner();
    $user->createToken('Zero', ['events:create', 'events:manage', 'app:zero']);
    User::factory()->create()->createToken('Theirs', ['events:create']);

    $this->get('/settings/api-tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Tokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'Zero')
            ->where('tokens.0.abilities', ['events:create', 'events:manage'])
            ->where('tokens.0.app', 'zero')
            ->where('tokens.0.last_used_at_diff', null)
            ->where('createdToken', null));
});

it('offers the abilities the API checks and the configured consumer apps', function () {
    config(['chronos.consumers' => ['zero' => 'Open in Mail', 'tempo' => 'Open in Tempo']]);
    tokenOwner();

    $this->get('/settings/api-tokens')
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilityOptions.0.value', 'events:create')
            ->where('abilityOptions.1.value', 'events:manage')
            ->has('abilityOptions', 2)
            ->where('appOptions', ['zero', 'tempo']));
});

it('creates a token with the chosen abilities and app, and reveals the plaintext exactly once', function () {
    $user = tokenOwner();

    $this->post('/settings/api-tokens', [
        'name' => 'Zero',
        'abilities' => ['events:create', 'events:manage'],
        'app' => 'zero',
    ])->assertRedirect('/settings/api-tokens');

    expect($user->tokens()->sole()->abilities)->toBe(['events:create', 'events:manage', 'app:zero']);

    $plain = null;

    $this->get('/settings/api-tokens')
        ->assertInertia(function (Assert $page) use (&$plain) {
            $plain = $page->toArray()['props']['createdToken'];

            return $page->where('createdToken', fn ($token) => is_string($token) && $token !== '');
        });

    expect($plain)->toBeString()->not->toBeEmpty();

    $this->get('/settings/api-tokens')
        ->assertInertia(fn (Assert $page) => $page->where('createdToken', null));
});

it('creates a token that is not scoped to an app', function () {
    $user = tokenOwner();

    $this->post('/settings/api-tokens', ['name' => 'Script', 'abilities' => ['events:create']])
        ->assertRedirect('/settings/api-tokens');

    expect($user->tokens()->sole()->abilities)->toBe(['events:create']);
});

it('never stores the plaintext token', function () {
    $user = tokenOwner();

    $this->post('/settings/api-tokens', ['name' => 'Zero', 'abilities' => ['events:create']]);

    $plain = (string) session('createdToken');
    $stored = (string) $user->tokens()->sole()->token;

    expect($plain)->not->toBeEmpty()
        ->and($stored)->not->toBe($plain)
        ->and(str_contains($plain, $stored))->toBeFalse();
});

it('validates the token name', function (mixed $name) {
    tokenOwner();

    $this->post('/settings/api-tokens', ['name' => $name, 'abilities' => ['events:create']])
        ->assertSessionHasErrors('name');

    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'missing' => '',
    'too long' => str_repeat('a', 256),
]);

it('refuses abilities the API does not know', function (mixed $abilities, string $error) {
    tokenOwner();

    $this->post('/settings/api-tokens', ['name' => 'Zero', 'abilities' => $abilities, 'app' => 'zero'])
        ->assertSessionHasErrors($error);

    expect(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'none' => [[], 'abilities'],
    'not a list' => ['events:create', 'abilities'],
    'keyed' => [['a' => 'events:create'], 'abilities'],
    'unknown' => [['events:create', 'users:read'], 'abilities.1'],
    'wildcard' => [['*'], 'abilities.0'],
    'an app smuggled in as an ability' => [['events:create', 'app:tempo'], 'abilities.1'],
    'a duplicate' => [['events:create', 'events:create'], 'abilities.0'],
]);

it('only scopes a token to a configured consumer app', function () {
    config(['chronos.consumers' => ['zero' => 'Open in Mail']]);
    tokenOwner();

    $this->post('/settings/api-tokens', ['name' => 'Zero', 'abilities' => ['events:create'], 'app' => 'billr'])
        ->assertSessionHasErrors('app');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

// The manage endpoints answer 403 to a token with no app, so one would be useless.
it('requires an app for a token that may manage events', function () {
    tokenOwner();

    $this->post('/settings/api-tokens', ['name' => 'Zero', 'abilities' => ['events:create', 'events:manage']])
        ->assertSessionHasErrors(['app' => 'Choose the app this token manages events for.']);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('revokes a token the user owns', function () {
    $user = tokenOwner();
    $token = $user->createToken('Zero', ['events:create'])->accessToken;

    $this->delete("/settings/api-tokens/{$token->getKey()}")
        ->assertRedirect('/settings/api-tokens');

    assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);
});

it('cannot revoke another user’s token', function () {
    tokenOwner();
    $theirs = User::factory()->create()->createToken('Theirs', ['events:create'])->accessToken;

    $this->delete("/settings/api-tokens/{$theirs->getKey()}")->assertNotFound();

    assertDatabaseHas('personal_access_tokens', ['id' => $theirs->getKey()]);
});

it('404s on a token that does not exist', function () {
    tokenOwner();

    $this->delete('/settings/api-tokens/99999')->assertNotFound();
});

it('mints a token that creates events over the API', function () {
    $user = tokenOwner();
    $this->post('/settings/api-tokens', ['name' => 'Script', 'abilities' => ['events:create']]);
    $plain = (string) session('createdToken');

    asFreshApiClient();

    $this->withToken($plain)->postJson('/api/v1/events', newEventPayload())->assertCreated();
    $this->withToken($plain)->getJson('/api/v1/events')->assertForbidden();

    expect($user->calendars()->sole()->events()->count())->toBe(1);
});

it('mints a token that manages its own app’s events over the API', function () {
    tokenOwner();
    $this->post('/settings/api-tokens', ['name' => 'Zero', 'abilities' => ['events:manage'], 'app' => 'zero']);
    $plain = (string) session('createdToken');

    asFreshApiClient();

    $this->withToken($plain)->getJson('/api/v1/events')->assertOk();
    $this->withToken($plain)->postJson('/api/v1/events', newEventPayload())->assertForbidden();
});

it('rejects the API call once the token is revoked', function () {
    $user = tokenOwner();
    $created = $user->createToken('Script', ['events:create']);

    $this->delete("/settings/api-tokens/{$created->accessToken->getKey()}");

    asFreshApiClient();

    $this->withToken($created->plainTextToken)->postJson('/api/v1/events', newEventPayload())->assertUnauthorized();
});

/**
 * CHRON-54 decided these tokens do not expire: the consumers are unattended and
 * have no refresh flow, so an expiry would quietly stop them creating events.
 */
it('mints a token that does not expire', function () {
    $user = tokenOwner();
    $this->post('/settings/api-tokens', ['name' => 'Script', 'abilities' => ['events:create']]);
    $plain = (string) session('createdToken');

    expect($user->tokens()->sole()->expires_at)->toBeNull();

    $this->travel(2)->years();
    asFreshApiClient();

    $this->withToken($plain)->postJson('/api/v1/events', newEventPayload())->assertCreated();
});

// The prefix is part of the hashed secret, so a consumer's existing token is unaffected.
it('keeps accepting a token minted before the prefix', function () {
    $user = User::factory()->create();
    config(['sanctum.token_prefix' => '']);
    $plain = $user->createToken('Zero', ['events:create'])->plainTextToken;
    config(['sanctum.token_prefix' => 'chronos_']);

    $this->withToken($plain)->postJson('/api/v1/events', newEventPayload())->assertCreated();
});

// Secret scanners find a leaked token by its prefix, and the app's name tells whose it is.
it('prefixes the plaintext with the app name', function () {
    $plain = app(CreateApiTokenAction::class)->handle(User::factory()->create(), 'Script', [ApiAbility::EventsCreate], null);
    [, $secret] = explode('|', $plain, 2);

    expect(config('sanctum.token_prefix'))->toBe(Str::slug((string) config('app.name'), '_').'_')
        ->and($secret)->toStartWith((string) config('sanctum.token_prefix'));
});
