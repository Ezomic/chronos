<?php

use App\Models\Calendar;
use App\Models\EventTemplate;
use App\Models\User;

function writableCalendar(User $user): Calendar
{
    return $user->calendars()->where('is_writable', true)->firstOrFail();
}

it('renders the templates settings page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('event-templates.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/EventTemplates')
            ->has('templates')
            ->has('calendars'));
});

it('creates a template owned by the user', function () {
    $user = User::factory()->create();
    $calendar = writableCalendar($user);

    $this->actingAs($user)
        ->post(route('event-templates.store'), [
            'name' => 'Weekly 1:1',
            'calendar_id' => $calendar->id,
            'title' => '1:1 with manager',
            'all_day' => false,
            'duration_minutes' => 30,
            'default_start_time' => '09:00',
            'frequency' => 'weekly',
            'reminder_minutes' => 10,
        ])
        ->assertRedirect();

    $template = EventTemplate::query()->where('name', 'Weekly 1:1')->firstOrFail();
    expect($template->user_id)->toBe($user->id)
        ->and($template->calendar_id)->toBe($calendar->id)
        ->and($template->duration_minutes)->toBe(30)
        ->and($template->default_start_time)->toBe('09:00')
        ->and($template->frequency)->toBe('weekly')
        ->and($template->reminder_minutes)->toBe(10);
});

it('rejects a template without a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('event-templates.store'), [
            'title' => 'No name',
            'duration_minutes' => 60,
        ])
        ->assertSessionHasErrors('name');
});

it('rejects a calendar_id the user cannot write to', function () {
    $user = User::factory()->create();
    $mirrored = Calendar::factory()->mirrored()->for($user)->create();

    $this->actingAs($user)
        ->post(route('event-templates.store'), [
            'name' => 'Bad calendar',
            'title' => 'Nope',
            'calendar_id' => $mirrored->id,
            'duration_minutes' => 60,
        ])
        ->assertSessionHasErrors('calendar_id');
});

it('rejects another user\'s calendar_id', function () {
    $user = User::factory()->create();
    $other = Calendar::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->post(route('event-templates.store'), [
            'name' => 'Foreign calendar',
            'title' => 'Nope',
            'calendar_id' => $other->id,
            'duration_minutes' => 60,
        ])
        ->assertSessionHasErrors('calendar_id');
});

it('rejects an out-of-range reminder', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('event-templates.store'), [
            'name' => 'Odd reminder',
            'title' => 'Nope',
            'duration_minutes' => 60,
            'reminder_minutes' => 7,
        ])
        ->assertSessionHasErrors('reminder_minutes');
});

it('allows a null calendar_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('event-templates.store'), [
            'name' => 'No calendar',
            'title' => 'Floating',
            'calendar_id' => null,
            'duration_minutes' => 60,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(EventTemplate::query()->where('name', 'No calendar')->firstOrFail()->calendar_id)
        ->toBeNull();
});

it('updates an owned template', function () {
    $user = User::factory()->create();
    $template = EventTemplate::factory()->for($user)->create([
        'name' => 'Old',
        'calendar_id' => writableCalendar($user)->id,
    ]);

    $this->actingAs($user)
        ->patch(route('event-templates.update', $template), [
            'name' => 'New',
            'title' => 'Updated title',
            'calendar_id' => $template->calendar_id,
            'duration_minutes' => 90,
        ])
        ->assertRedirect();

    expect($template->refresh()->name)->toBe('New')
        ->and($template->title)->toBe('Updated title')
        ->and($template->duration_minutes)->toBe(90);
});

it('forbids updating another user\'s template', function () {
    $user = User::factory()->create();
    $other = EventTemplate::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->patch(route('event-templates.update', $other), [
            'name' => 'Hacked',
            'title' => 'Hacked',
            'duration_minutes' => 60,
        ])
        ->assertForbidden();
});

it('deletes an owned template', function () {
    $user = User::factory()->create();
    $template = EventTemplate::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('event-templates.destroy', $template))
        ->assertRedirect();

    expect(EventTemplate::query()->find($template->id))->toBeNull();
});

it('forbids deleting another user\'s template', function () {
    $user = User::factory()->create();
    $other = EventTemplate::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->delete(route('event-templates.destroy', $other))
        ->assertForbidden();
});

it('nulls a template\'s calendar_id when its calendar is deleted', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->for($user)->create();
    $template = EventTemplate::factory()->for($user)->create(['calendar_id' => $calendar->id]);

    $calendar->delete();

    expect($template->refresh()->calendar_id)->toBeNull();
});

it('stores the duration the event sheet derives when saving as a template', function () {
    $user = User::factory()->create();
    $calendar = writableCalendar($user);

    // What EventSheet posts for a 2-hour timed event starting 14:00.
    $this->actingAs($user)
        ->post(route('event-templates.store'), [
            'name' => 'Afternoon block',
            'calendar_id' => $calendar->id,
            'title' => 'Focus time',
            'all_day' => false,
            'duration_minutes' => 120,
            'default_start_time' => '14:00',
            'frequency' => null,
            'reminder_minutes' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $template = EventTemplate::query()->where('name', 'Afternoon block')->firstOrFail();
    expect($template->duration_minutes)->toBe(120)
        ->and($template->default_start_time)->toBe('14:00')
        ->and($template->all_day)->toBeFalse();
});

it('stores an all-day span as whole days of minutes', function () {
    $user = User::factory()->create();

    // A 3-day all-day event: 3 x 1440.
    $this->actingAs($user)
        ->post(route('event-templates.store'), [
            'name' => 'Conference',
            'title' => 'Conference',
            'all_day' => true,
            'duration_minutes' => 4320,
            'default_start_time' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $template = EventTemplate::query()->where('name', 'Conference')->firstOrFail();
    expect($template->all_day)->toBeTrue()
        ->and($template->duration_minutes)->toBe(4320)
        ->and($template->default_start_time)->toBeNull();
});

it('feeds templates into the calendar page', function () {
    $user = User::factory()->create();
    EventTemplate::factory()->for($user)->create(['name' => 'Standup']);

    $this->actingAs($user)
        ->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('calendar/Index')
            ->has('templates', 1)
            ->where('templates.0.name', 'Standup'));
});
