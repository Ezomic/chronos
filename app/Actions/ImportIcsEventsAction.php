<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Calendar;
use App\Models\Event;
use Carbon\CarbonImmutable;

class ImportIcsEventsAction
{
    /**
     * Write parsed iCalendar events onto a writable calendar as ordinary
     * editable events, not as a read-only mirror.
     *
     * Re-importing the same file does not duplicate: the file's UID lands in
     * external_id, whose unique index per calendar is already the rule that one
     * source row means one event, in the spirit of CHRON-48.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array{imported: int, skipped: int}
     */
    public function handle(Calendar $calendar, array $events): array
    {
        $imported = 0;
        $skipped = 0;

        foreach ($events as $event) {
            $uid = is_string($event['uid'] ?? null) && $event['uid'] !== ''
                ? $event['uid']
                : null;

            if ($uid !== null && $this->alreadyHere($calendar, $uid)) {
                $skipped++;

                continue;
            }

            $this->write($calendar, $event, $uid);
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function alreadyHere(Calendar $calendar, string $uid): bool
    {
        return $calendar->events()->where('external_id', $uid)->exists();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function write(Calendar $calendar, array $event, ?string $uid): void
    {
        $starts = $event['starts_at'];
        $ends = $event['ends_at'];

        if (! $starts instanceof CarbonImmutable || ! $ends instanceof CarbonImmutable) {
            return;
        }

        (new Event)->forceFill([
            'calendar_id' => $calendar->id,
            'title' => $event['title'],
            'description' => $event['description'],
            'location' => $event['location'],
            'starts_at' => $starts,
            'ends_at' => $ends,
            'all_day' => $event['all_day'],
            'timezone' => $event['timezone'],
            'rrule' => $event['rrule'],
            'external_id' => $uid,
        ])->save();
    }
}
