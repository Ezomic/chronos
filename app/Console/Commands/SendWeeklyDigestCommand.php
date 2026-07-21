<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use App\Services\Calendar\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class SendWeeklyDigestCommand extends Command
{
    protected $signature = 'chronos:weekly-digest';

    protected $description = 'Email each user a summary of the coming week\'s events';

    public function __construct(private readonly RecurrenceExpander $expander)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $weekStart = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(7);

        $users = User::query()
            ->whereHas('calendars', fn ($query) => $query->where('is_visible', true))
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $items = $this->weekItems($user, $weekStart, $weekEnd);

            // Skip a quiet week; an empty digest is just noise.
            if ($items === []) {
                continue;
            }

            $user->notify(new WeeklyDigestNotification($weekStart, $items));
            $sent++;
        }

        $this->info("Sent {$sent} digest(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{title: string, starts_at: CarbonInterface, all_day: bool, location: string|null, timezone: string}>
     */
    private function weekItems(User $user, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $ownedVisible = fn ($query) => $query
            ->where('user_id', $user->id)
            ->where('is_visible', true);

        $single = Event::query()
            ->whereHas('calendar', $ownedVisible)
            ->whereNull('rrule')
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart)
            ->get();

        $recurring = Event::query()
            ->whereHas('calendar', $ownedVisible)
            ->whereNotNull('rrule')
            ->where('starts_at', '<', $weekEnd)
            ->get();

        $items = [];

        foreach ($single as $event) {
            $items[] = $this->item($event, $event->starts_at);
        }

        foreach ($recurring as $master) {
            foreach ($this->expander->expand($master, $weekStart, $weekEnd) as $occurrence) {
                $items[] = $this->item($master, $occurrence['starts_at']);
            }
        }

        usort($items, fn ($a, $b) => $a['starts_at']->getTimestamp() <=> $b['starts_at']->getTimestamp());

        return $items;
    }

    /**
     * @return array{title: string, starts_at: CarbonInterface, all_day: bool, location: string|null, timezone: string}
     */
    private function item(Event $event, CarbonInterface $startsAt): array
    {
        return [
            'title' => $event->title,
            'starts_at' => $startsAt,
            'all_day' => $event->all_day,
            'location' => $event->location,
            'timezone' => $event->timezone,
        ];
    }
}
