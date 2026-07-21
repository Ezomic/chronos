<?php

use Illuminate\Support\Facades\Route;

// Chronos is passwordless, so passkey management must not sit behind the
// password.confirm middleware (there's no password to confirm).
it('does not gate passkey management behind password confirmation', function (string $name) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->not->toContain('password.confirm');
})->with([
    'passkey.store',
    'passkey.destroy',
    'passkey.registration-options',
]);
