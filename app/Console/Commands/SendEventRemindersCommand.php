<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Notifications\EventReminderNotification;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendEventRemindersCommand extends Command
{
    protected $signature = 'chronos:send-reminders';

    protected $description = 'Notify owners of upcoming events whose reminder time has arrived';

    public function __construct(private readonly RecurrenceExpander $expander)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        // A reminder is due once starts_at - reminder_minutes has passed, which
        // means the (occurrence) start falls between now and now + the largest
        // offset. Scope queries to that window, then confirm each in PHP.
        $horizon = $now->addMinutes(max(Event::REMINDER_CHOICES));

        $sent = $this->remindSingleEvents($now, $horizon)
            + $this->remindRecurringEvents($now, $horizon);

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }

    private function remindSingleEvents(CarbonImmutable $now, CarbonImmutable $horizon): int
    {
        $events = Event::query()
            ->whereNull('rrule')
            ->whereNull('reminder_sent_at')
            ->whereNotNull('reminder_minutes')
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $horizon)
            ->with('calendar.user')
            ->get();

        $sent = 0;

        foreach ($events as $event) {
            $reminderAt = $event->starts_at->subMinutes((int) $event->reminder_minutes);

            if ($reminderAt->greaterThan($now)) {
                continue;
            }

            $user = $event->calendar?->user;

            if ($user === null) {
                continue;
            }

            $user->notify(new EventReminderNotification($event));
            $event->forceFill(['reminder_sent_at' => $now])->save();
            $sent++;
        }

        return $sent;
    }

    /**
     * Send a reminder for the next occurrence of each recurring event whose
     * reminder time has arrived. reminder_sent_for tracks the last occurrence
     * reminded, so each occurrence fires at most once.
     */
    private function remindRecurringEvents(CarbonImmutable $now, CarbonImmutable $horizon): int
    {
        $events = Event::query()
            ->whereNotNull('rrule')
            ->whereNotNull('reminder_minutes')
            ->with('calendar.user')
            ->get();

        $sent = 0;

        foreach ($events as $event) {
            $user = $event->calendar?->user;

            if ($user === null) {
                continue;
            }

            foreach ($this->expander->expand($event, $now, $horizon) as $occurrence) {
                $start = $occurrence['starts_at'];
                $reminderAt = $start->subMinutes((int) $event->reminder_minutes);

                $alreadySent = $event->reminder_sent_for !== null
                    && $start->lessThanOrEqualTo($event->reminder_sent_for);

                if ($reminderAt->greaterThan($now) || $alreadySent) {
                    continue;
                }

                $user->notify(new EventReminderNotification($event, $start));
                $event->forceFill(['reminder_sent_for' => $start])->save();
                $sent++;

                // Only the earliest due occurrence per run; the next run picks
                // up the following one.
                break;
            }
        }

        return $sent;
    }
}
