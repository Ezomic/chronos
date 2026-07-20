<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Notifications\EventReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendEventRemindersCommand extends Command
{
    protected $signature = 'chronos:send-reminders';

    protected $description = 'Notify owners of upcoming events whose reminder time has arrived';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        // A reminder is due once starts_at - reminder_minutes has passed, which
        // means starts_at falls between now and now + the largest offset. Scope
        // the query to that window, then confirm each in PHP.
        $horizon = $now->addMinutes(max(Event::REMINDER_CHOICES));

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

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
