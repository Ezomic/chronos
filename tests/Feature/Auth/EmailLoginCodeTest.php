<?php

declare(strict_types=1);

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

it('sends a login code to an existing user and redirects to verify', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->post('/login/code', ['email' => 'user@example.com'])
        ->assertRedirect(route('login.code.verify'));

    Mail::assertSent(LoginCodeMail::class, fn ($mail) => $mail->hasTo('user@example.com'));
    expect(session('login-code-email'))->toBe('user@example.com');
});

it('does not send an email for an unknown address but still redirects the same way', function () {
    Mail::fake();

    $this->post('/login/code', ['email' => 'nobody@example.com'])
        ->assertRedirect(route('login.code.verify'));

    Mail::assertNothingSent();
    expect(session('login-code-email'))->toBe('nobody@example.com');
});

it('redirects away from the verify page with no pending email', function () {
    $this->get('/login/code/verify')->assertRedirect(route('login.code.create'));
});

it('logs the user in with the correct code', function () {
    $user = User::factory()->create(['email' => 'user@example.com']);
    Cache::put('login-code:user@example.com', ['hash' => Hash::make('123456'), 'attempts' => 0], now()->addMinutes(10));
    $this->withSession(['login-code-email' => 'user@example.com']);

    $this->post('/login/code/verify', ['code' => '123456'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rejects an incorrect code and increments attempts', function () {
    User::factory()->create(['email' => 'user@example.com']);
    Cache::put('login-code:user@example.com', ['hash' => Hash::make('123456'), 'attempts' => 0], now()->addMinutes(10));
    $this->withSession(['login-code-email' => 'user@example.com']);

    $this->post('/login/code/verify', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(Cache::get('login-code:user@example.com')['attempts'])->toBe(1);
});

it('expires the code after 5 failed attempts', function () {
    User::factory()->create(['email' => 'user@example.com']);
    Cache::put('login-code:user@example.com', ['hash' => Hash::make('123456'), 'attempts' => 5], now()->addMinutes(10));
    $this->withSession(['login-code-email' => 'user@example.com']);

    $this->post('/login/code/verify', ['code' => '123456'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(Cache::get('login-code:user@example.com'))->toBeNull();
});

it('rejects verification with no pending email in session', function () {
    $this->post('/login/code/verify', ['code' => '123456'])
        ->assertRedirect(route('login.code.create'));

    $this->assertGuest();
});
