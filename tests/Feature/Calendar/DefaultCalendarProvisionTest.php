<?php

use App\Actions\CreateDefaultCalendarAction;
use App\Models\Calendar;
use App\Models\User;

it('gives a newly created user one default writable calendar', function () {
    $user = User::factory()->create();

    $defaults = Calendar::query()
        ->where('user_id', $user->id)
        ->where('is_default', true)
        ->get();

    expect($defaults)->toHaveCount(1)
        ->and($defaults->first()->is_writable)->toBeTrue()
        ->and($defaults->first()->name)->toBe('Personal');
});

it('does not create a second default calendar for the same user', function () {
    $user = User::factory()->create();

    // Simulate the observer/action running again (e.g. a later login).
    app(CreateDefaultCalendarAction::class)->handle($user);

    expect(Calendar::where('user_id', $user->id)->where('is_default', true)->count())->toBe(1);
});

it('provisions a calendar for an SSO-created user so the events API has a target', function () {
    // id-client provisions via a plain create/save, which fires the observer.
    $user = User::query()->create([
        'name' => 'SSO User',
        'email' => 'sso@example.com',
    ]);

    expect(Calendar::where('user_id', $user->id)->where('is_default', true)->where('is_writable', true)->exists())
        ->toBeTrue();
});
