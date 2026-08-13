<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

function icsFile(string $body, string $name = 'invite.ics'): UploadedFile
{
    $document = implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Test//EN',
        $body,
        'END:VCALENDAR',
    ])."\r\n";

    return UploadedFile::fake()->createWithContent($name, $document);
}

function timedInvite(string $uid = 'invite-1'): string
{
    return implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:'.$uid,
        'SUMMARY:Kickoff with Acme',
        'LOCATION:Room A',
        'DTSTART:20260720T090000Z',
        'DTEND:20260720T093000Z',
        'END:VEVENT',
    ]);
}

function previewFile(User $user, UploadedFile $file): TestResponse
{
    return test()->actingAs($user)->post(route('imports.preview'), ['file' => $file]);
}

function importToken(): ?string
{
    return session('inertia.flash_data')['icsImport']['token'] ?? null;
}

it('previews a file without writing anything', function () {
    $user = User::factory()->create();

    previewFile($user, icsFile(timedInvite()))->assertRedirect();

    $preview = session('inertia.flash_data')['icsImport'] ?? null;

    expect($preview)->not->toBeNull()
        ->and($preview['count'])->toBe(1)
        ->and($preview['events'][0]['title'])->toBe('Kickoff with Acme')
        // Nothing is written until the user confirms.
        ->and(Event::query()->count())->toBe(0);
});

it('imports the previewed file as editable local events', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    previewFile($user, icsFile(timedInvite()));

    $this->actingAs($user)
        ->post(route('imports.store'), ['token' => importToken(), 'calendar_id' => $calendar->id])
        ->assertRedirect();

    $event = Event::query()->firstOrFail();

    expect($event->title)->toBe('Kickoff with Acme')
        ->and($event->location)->toBe('Room A')
        ->and($event->calendar_id)->toBe($calendar->id)
        ->and($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-07-20 09:00')
        // On a writable calendar, so the sheet offers a form rather than a
        // read-only view.
        ->and($event->calendar->is_writable)->toBeTrue();
});

it('does not duplicate when the same file is imported twice', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    foreach (range(1, 2) as $ignored) {
        previewFile($user, icsFile(timedInvite()));
        $this->actingAs($user)->post(route('imports.store'), [
            'token' => importToken(),
            'calendar_id' => $calendar->id,
        ]);
    }

    expect(Event::query()->count())->toBe(1);
});

it('keeps a recurring event repeating rather than flattening it', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    previewFile($user, icsFile(implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:weekly-1',
        'SUMMARY:Standup',
        'DTSTART:20260706T090000Z',
        'DTEND:20260706T091500Z',
        'RRULE:FREQ=WEEKLY;COUNT=6',
        'END:VEVENT',
    ])));

    $this->actingAs($user)->post(route('imports.store'), [
        'token' => importToken(),
        'calendar_id' => $calendar->id,
    ]);

    // One row carrying the rule, not six.
    expect(Event::query()->count())->toBe(1)
        ->and(Event::query()->firstOrFail()->rrule)->toBe('FREQ=WEEKLY;COUNT=6');
});

it('imports an all-day event as a midnight-UTC span', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    previewFile($user, icsFile(implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:allday-1',
        'SUMMARY:Conference',
        'DTSTART;VALUE=DATE:20260720',
        'DTEND;VALUE=DATE:20260721',
        'END:VEVENT',
    ])));

    $this->actingAs($user)->post(route('imports.store'), [
        'token' => importToken(),
        'calendar_id' => $calendar->id,
    ]);

    $event = Event::query()->firstOrFail();

    expect($event->all_day)->toBeTrue()
        ->and($event->timezone)->toBe('UTC')
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe('2026-07-20 00:00')
        ->and($event->ends_at->format('Y-m-d H:i'))->toBe('2026-07-21 00:00');
});

it('rejects a file that is not a calendar', function () {
    $user = User::factory()->create();

    previewFile($user, UploadedFile::fake()->createWithContent('notes.ics', 'just some text'))
        ->assertRedirect();

    $toast = session('inertia.flash_data')['toast'] ?? null;

    expect($toast['type'])->toBe('error')
        ->and(session('inertia.flash_data')['icsImport'] ?? null)->toBeNull();
});

it('rejects a file over the size cap', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('imports.preview'), [
            'file' => UploadedFile::fake()->create('huge.ics', 4096),
        ])
        ->assertSessionHasErrors('file');
});

it('will not import into another user calendar', function () {
    $user = User::factory()->create();
    $theirs = Calendar::factory()->create();

    previewFile($user, icsFile(timedInvite()));

    $this->actingAs($user)->post(route('imports.store'), [
        'token' => importToken(),
        'calendar_id' => $theirs->id,
    ])->assertRedirect();

    expect(Event::query()->count())->toBe(0);
});

it('will not import into a mirrored calendar', function () {
    $user = User::factory()->create();
    $mirrored = Calendar::factory()->for($user)->mirrored()->create();

    previewFile($user, icsFile(timedInvite()));

    $this->actingAs($user)->post(route('imports.store'), [
        'token' => importToken(),
        'calendar_id' => $mirrored->id,
    ])->assertRedirect();

    expect(Event::query()->count())->toBe(0);
});

it('rejects a token that is not a plain token', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    // A traversal attempt never reaches the filesystem.
    $this->actingAs($user)->post(route('imports.store'), [
        'token' => '../../../../etc/passwd',
        'calendar_id' => $calendar->id,
    ])->assertSessionHasErrors('token');
});

it('says so when the upload has expired', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    $this->actingAs($user)->post(route('imports.store'), [
        'token' => str_repeat('a', 40),
        'calendar_id' => $calendar->id,
    ])->assertRedirect();

    expect(session('inertia.flash_data')['toast']['type'] ?? null)->toBe('error');
});

it('removes the upload once it has been imported', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    previewFile($user, icsFile(timedInvite()));
    $token = importToken();

    expect(Storage::disk('local')->exists("ics-imports/{$token}.ics"))->toBeTrue();

    $this->actingAs($user)->post(route('imports.store'), [
        'token' => $token,
        'calendar_id' => $calendar->id,
    ]);

    expect(Storage::disk('local')->exists("ics-imports/{$token}.ics"))->toBeFalse();
});

it('imports several events from one file', function () {
    $user = User::factory()->create();
    $calendar = $user->calendars()->where('is_default', true)->firstOrFail();

    previewFile($user, icsFile(timedInvite('one')."\r\n".timedInvite('two')));

    $this->actingAs($user)->post(route('imports.store'), [
        'token' => importToken(),
        'calendar_id' => $calendar->id,
    ]);

    expect(Event::query()->count())->toBe(2);
});
