<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Calendar;
use App\Models\ConnectedAccount;
use App\Models\Event;
use App\Services\Calendar\CalendarSource;
use App\Services\Calendar\GoogleCalendarService;
use App\Services\Calendar\OAuthTokenRefresher;
use Carbon\CarbonImmutable;
use RuntimeException;

class SyncConnectedAccountAction
{
    public function __construct(
        private readonly OAuthTokenRefresher $refresher,
        private readonly GoogleCalendarService $google,
    ) {}

    public function handle(ConnectedAccount $account): void
    {
        $account->update(['sync_status' => 'syncing', 'sync_status_since' => now(), 'sync_error' => null]);

        try {
            $source = $this->sourceFor($account);
            $token = $this->refresher->freshAccessToken($account);

            // Windowed full refresh. Not sync tokens: Google's syncToken is
            // mutually exclusive with timeMin/timeMax, so it would drag in every
            // instance a calendar has ever had.
            $from = CarbonImmutable::now()->subMonths(3);
            $to = CarbonImmutable::now()->addMonths(12);

            foreach ($source->calendars($token) as $remote) {
                $calendar = $this->upsertCalendar($account, $remote);
                $events = $source->events($token, $remote['external_id'], $from, $to);
                $this->syncEvents($calendar, $events, $from, $to);
            }

            $account->update(['sync_status' => 'idle', 'sync_status_since' => now(), 'last_synced_at' => now()]);
        } catch (\Throwable $e) {
            $account->update(['sync_status' => 'error', 'sync_status_since' => now(), 'sync_error' => $e->getMessage()]);

            throw $e;
        }
    }

    private function sourceFor(ConnectedAccount $account): CalendarSource
    {
        return match ($account->provider) {
            ConnectedAccount::PROVIDER_GOOGLE => $this->google,
            default => throw new RuntimeException("No calendar source for provider {$account->provider}."),
        };
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function upsertCalendar(ConnectedAccount $account, array $remote): Calendar
    {
        return Calendar::query()->updateOrCreate(
            ['connected_account_id' => $account->id, 'external_id' => $remote['external_id']],
            [
                'user_id' => $account->user_id,
                'name' => $remote['name'],
                'color' => $remote['color'] ?? Calendar::COLOR_PALETTE[0],
                'timezone' => $remote['timezone'],
                'is_writable' => false,
                'synced_at' => now(),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function syncEvents(Calendar $calendar, array $events, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $seen = [];

        foreach ($events as $event) {
            Event::query()->updateOrCreate(
                ['calendar_id' => $calendar->id, 'external_id' => $event['external_id']],
                [
                    'title' => $event['title'],
                    'description' => $event['description'],
                    'location' => $event['location'],
                    'starts_at' => $event['starts_at'],
                    'ends_at' => $event['ends_at'],
                    'all_day' => $event['all_day'],
                    'timezone' => $event['timezone'],
                    'external_etag' => $event['external_etag'],
                ],
            );

            $seen[] = $event['external_id'];
        }

        // Drop mirrored events that vanished upstream, but only within the
        // synced window so events outside it are left untouched.
        Event::query()
            ->where('calendar_id', $calendar->id)
            ->whereNotNull('external_id')
            ->whereNotIn('external_id', $seen ?: [''])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->delete();
    }
}
