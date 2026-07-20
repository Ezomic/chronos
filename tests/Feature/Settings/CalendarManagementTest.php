<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;

it('renders the calendars settings page with the palette', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('calendars.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Calendars')
            ->has('calendars')
            ->has('accounts')
            ->where('palette', Calendar::COLOR_PALETTE));
});

it('creates a writable local calendar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('calendars.store'), [
            'name' => 'Work',
            'color' => Calendar::COLOR_PALETTE[1],
        ])
        ->assertRedirect();

    $calendar = Calendar::query()->where('name', 'Work')->firstOrFail();
    expect($calendar->user_id)->toBe($user->id)
        ->and($calendar->is_writable)->toBeTrue()
        ->and($calendar->is_default)->toBeFalse()
        ->and($calendar->color)->toBe(Calendar::COLOR_PALETTE[1]);
});

it('rejects a calendar without a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('calendars.store'), ['color' => Calendar::COLOR_PALETTE[0]])
        ->assertSessionHasErrors('name');
});

it('rejects a color outside the palette', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('calendars.store'), ['name' => 'Bad', 'color' => '#123456'])
        ->assertSessionHasErrors('color');
});

it('updates the name and color of an owned writable calendar', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create(['name' => 'Old']);

    $this->actingAs($user)
        ->patch(route('calendars.update', $calendar), [
            'name' => 'New',
            'color' => Calendar::COLOR_PALETTE[2],
        ])
        ->assertRedirect();

    expect($calendar->refresh()->name)->toBe('New')
        ->and($calendar->color)->toBe(Calendar::COLOR_PALETTE[2]);
});

it('forbids editing a read-only mirrored calendar', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->mirrored()->for($user)->create();

    $this->actingAs($user)
        ->patch(route('calendars.update', $calendar), [
            'name' => 'Hacked',
            'color' => Calendar::COLOR_PALETTE[0],
        ])
        ->assertForbidden();
});

it('forbids editing another user\'s calendar', function () {
    $user = User::factory()->create();
    $other = Calendar::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->patch(route('calendars.update', $other), [
            'name' => 'Nope',
            'color' => Calendar::COLOR_PALETTE[0],
        ])
        ->assertForbidden();
});

it('toggles visibility, including on a read-only mirrored calendar', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->mirrored()->for($user)->create(['is_visible' => true]);

    $this->actingAs($user)
        ->patch(route('calendars.visibility', $calendar), ['is_visible' => false])
        ->assertRedirect();

    expect($calendar->refresh()->is_visible)->toBeFalse();
});

it('deletes a non-default writable calendar and cascades its events', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $event = Event::factory()->for($calendar)->create();

    $this->actingAs($user)
        ->delete(route('calendars.destroy', $calendar))
        ->assertRedirect();

    expect(Calendar::query()->find($calendar->id))->toBeNull()
        ->and(Event::query()->find($event->id))->toBeNull();
});

it('forbids deleting the default calendar', function () {
    $user = User::factory()->create();
    $default = $user->calendars()->where('is_default', true)->firstOrFail();

    $this->actingAs($user)
        ->delete(route('calendars.destroy', $default))
        ->assertForbidden();

    expect(Calendar::query()->find($default->id))->not->toBeNull();
});

it('forbids deleting another user\'s calendar', function () {
    $user = User::factory()->create();
    $other = Calendar::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->delete(route('calendars.destroy', $other))
        ->assertForbidden();
});
