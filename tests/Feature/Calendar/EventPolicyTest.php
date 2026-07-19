<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;

it('lets the owner change an event on a writable local calendar', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['is_writable' => true]);
    $event = Event::factory()->for($calendar)->create();

    expect($user->can('update', $event))->toBeTrue()
        ->and($user->can('delete', $event))->toBeTrue()
        ->and($user->can('view', $event))->toBeTrue();
});

it('forbids changing an event on a mirrored read-only calendar', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->mirrored()->create();
    $event = Event::factory()->for($calendar)->external()->create();

    expect($user->can('view', $event))->toBeTrue()
        ->and($user->can('update', $event))->toBeFalse()
        ->and($user->can('delete', $event))->toBeFalse();
});

it('forbids another user from touching the event', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $calendar = Calendar::factory()->for($owner)->create(['is_writable' => true]);
    $event = Event::factory()->for($calendar)->create();

    expect($other->can('view', $event))->toBeFalse()
        ->and($other->can('update', $event))->toBeFalse()
        ->and($other->can('delete', $event))->toBeFalse();
});
