<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

function publishedCalendar(User $user): Calendar
{
    $calendar = Calendar::factory()->for($user)->create(['name' => 'Work', 'is_writable' => true]);

    test()->actingAs($user)->post(route('calendars.publish', $calendar))->assertRedirect();

    return $calendar->fresh();
}

function feedFor(Calendar $calendar): string
{
    return route('feeds.show', ['token' => $calendar->publish_token]);
}

it('publishes nothing until asked', function () {
    $calendar = Calendar::factory()->create();

    expect($calendar->publish_token)->toBeNull();
});

it('serves a valid iCalendar document at the feed URL', function () {
    $user = User::factory()->create();
    $calendar = publishedCalendar($user);

    Event::factory()->for($calendar)->create([
        'title' => 'Kickoff',
        'starts_at' => CarbonImmutable::now()->addDays(3)->startOfHour(),
        'ends_at' => CarbonImmutable::now()->addDays(3)->startOfHour()->addHour(),
        'timezone' => 'UTC',
    ]);

    $response = $this->get(feedFor($calendar))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($response->getContent())->toContain('BEGIN:VCALENDAR')
        ->and($response->getContent())->toContain('END:VCALENDAR')
        ->and($response->getContent())->toContain('SUMMARY:Kickoff')
        ->and($response->getContent())->toContain('X-WR-CALNAME:Work');
});

it('needs no login, which is the point', function () {
    $calendar = publishedCalendar(User::factory()->create());

    // No actingAs: a phone subscribing has no session.
    $this->get(feedFor($calendar))->assertOk();
});

it('answers an unknown token the same as an unpublished calendar', function () {
    $unpublished = Calendar::factory()->create();

    $wrongToken = $this->get(route('feeds.show', ['token' => str_repeat('a', 48)]));
    $wrongToken->assertNotFound();

    expect($unpublished->publish_token)->toBeNull()
        // Nothing in the response tells the two cases apart.
        ->and($wrongToken->getContent())->toBe(
            $this->get(route('feeds.show', ['token' => str_repeat('b', 48)]))->getContent(),
        );
});

it('stops serving the old URL once the token is rotated', function () {
    $user = User::factory()->create();
    $calendar = publishedCalendar($user);
    $oldUrl = feedFor($calendar);

    $this->actingAs($user)->post(route('calendars.publish', $calendar))->assertRedirect();

    $rotated = $calendar->fresh();

    expect($rotated->publish_token)->not->toBe($calendar->publish_token);

    $this->get($oldUrl)->assertNotFound();
    $this->get(feedFor($rotated))->assertOk();
});

it('stops serving once the feed is revoked', function () {
    $user = User::factory()->create();
    $calendar = publishedCalendar($user);
    $url = feedFor($calendar);

    $this->actingAs($user)->delete(route('calendars.unpublish', $calendar))->assertRedirect();

    $this->get($url)->assertNotFound();
    expect($calendar->fresh()->publish_token)->toBeNull();
});

it('will not let another user publish a calendar', function () {
    $mine = User::factory()->create();
    $calendar = Calendar::factory()->create();

    $this->actingAs($mine)->post(route('calendars.publish', $calendar))->assertForbidden();

    expect($calendar->fresh()->publish_token)->toBeNull();
});

it('sends recurrence as a rule rather than expanded instances', function () {
    $user = User::factory()->create();
    $calendar = publishedCalendar($user);

    Event::factory()->for($calendar)->create([
        'title' => 'Standup',
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY;INTERVAL=2',
    ]);

    $body = $this->get(feedFor($calendar))->assertOk()->getContent();

    expect($body)->toContain('RRULE:FREQ=WEEKLY;INTERVAL=2')
        // One VEVENT for the series, not one per occurrence.
        ->and(substr_count($body, 'BEGIN:VEVENT'))->toBe(1);
});

it('carries skipped occurrences as EXDATE', function () {
    $user = User::factory()->create();
    $calendar = publishedCalendar($user);

    Event::factory()->for($calendar)->create([
        'title' => 'Standup',
        'starts_at' => CarbonImmutable::parse('2026-07-06T09:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-06T09:15:00Z'),
        'timezone' => 'UTC',
        'rrule' => 'FREQ=WEEKLY',
        'excluded_dates' => ['2026-07-13 09:00:00'],
    ]);

    expect($this->get(feedFor($calendar))->getContent())->toContain('EXDATE');
});

it('writes an all-day event as dates', function () {
    $user = User::factory()->create();
    $calendar = publishedCalendar($user);

    Event::factory()->for($calendar)->allDay()->create([
        'title' => 'Conference',
        'starts_at' => CarbonImmutable::parse('2026-07-20T00:00:00Z'),
        'ends_at' => CarbonImmutable::parse('2026-07-21T00:00:00Z'),
    ]);

    $body = $this->get(feedFor($calendar))->getContent();

    expect($body)->toContain('DTSTART;VALUE=DATE:20260720')
        ->and($body)->toContain('DTEND;VALUE=DATE:20260721');
});

it('leaves deleted events out of the feed', function () {
    $user = User::factory()->create();
    $calendar = publishedCalendar($user);

    $event = Event::factory()->for($calendar)->create([
        'title' => 'Cancelled thing',
        'starts_at' => CarbonImmutable::now()->addDays(2),
        'ends_at' => CarbonImmutable::now()->addDays(2)->addHour(),
    ]);

    $event->delete();

    expect($this->get(feedFor($calendar))->getContent())->not->toContain('Cancelled thing');
});

it('serves only the calendar the token belongs to', function () {
    $user = User::factory()->create();
    $published = publishedCalendar($user);
    $other = Calendar::factory()->for($user)->create(['name' => 'Private']);

    Event::factory()->for($other)->create([
        'title' => 'Not in the feed',
        'starts_at' => CarbonImmutable::now()->addDay(),
        'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
    ]);

    expect($this->get(feedFor($published))->getContent())->not->toContain('Not in the feed');
});

it('tells caches and crawlers to keep out', function () {
    $calendar = publishedCalendar(User::factory()->create());

    $response = $this->get(feedFor($calendar))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('shows the owner their feed URL and nobody else', function () {
    $user = User::factory()->create();
    $calendar = publishedCalendar($user);

    $page = $this->actingAs($user)->get(route('calendars.edit'))->assertOk();

    $listed = collect($page->viewData('page')['props']['calendars']);
    $published = $listed->firstWhere('id', $calendar->id);
    $others = $listed->where('id', '!=', $calendar->id);

    expect($published['feed_url'])->toContain($calendar->publish_token)
        // Every other calendar is unpublished, so it has no URL to leak.
        ->and($others->pluck('feed_url')->filter()->all())->toBe([]);
});
