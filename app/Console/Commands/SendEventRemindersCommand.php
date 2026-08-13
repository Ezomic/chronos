<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventReminder;
use App\Notifications\EventReminderNotification;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

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

        // A reminder is due once starts_at - minutes_before has passed, which
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
        $reminders = $this->reminders(fn (Builder $query) => $query
            ->whereNull('rrule')
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $horizon))
            ->whereNull('sent_at');

        $sent = 0;

        foreach ($reminders->get() as $reminder) {
            $event = $reminder->event;
            $user = $event?->calendar?->user;

            if ($event === null || $user === null) {
                continue;
            }

            if ($event->starts_at->subMinutes($reminder->minutes_before)->greaterThan($now)) {
                continue;
            }

            $user->notify(new EventReminderNotification($event));
            $reminder->forceFill(['sent_at' => $now])->save();
            $sent++;
        }

        return $sent;
    }

    /**
     * Send the next due occurrence for each reminder on a repeating event.
     * sent_for tracks the last occurrence that reminder covered, so each
     * occurrence fires once per reminder rather than once per event.
     */
    private function remindRecurringEvents(CarbonImmutable $now, CarbonImmutable $horizon): int
    {
        $sent = 0;

        foreach ($this->reminders(fn (Builder $query) => $query->whereNotNull('rrule'))->get() as $reminder) {
            $event = $reminder->event;
            $user = $event?->calendar?->user;

            if ($event === null || $user === null) {
                continue;
            }

            foreach ($this->expander->expand($event, $now, $horizon) as $occurrence) {
                $start = $occurrence['starts_at'];

                $alreadySent = $reminder->sent_for !== null
                    && $start->lessThanOrEqualTo($reminder->sent_for);

                if ($start->subMinutes($reminder->minutes_before)->greaterThan($now) || $alreadySent) {
                    continue;
                }

                $user->notify(new EventReminderNotification($event, $start));
                $reminder->forceFill(['sent_for' => $start])->save();
                $sent++;

                // Only the earliest due occurrence per run; the next run picks
                // up the following one.
                break;
            }
        }

        return $sent;
    }

    /**
     * @param  \Closure(Builder<Event>): Builder<Event>  $scope
     * @return Builder<EventReminder>
     */
    private function reminders(\Closure $scope): Builder
    {
        return EventReminder::query()
            ->whereHas('event', $scope)
            ->with(['event.calendar.user', 'event.overrides']);
    }
}
