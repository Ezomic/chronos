<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SyncConnectedAccountJob;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\Calendar\IcsCalendarService;
use Illuminate\Validation\ValidationException;

class SubscribeToIcsFeedAction
{
    public function __construct(private readonly IcsCalendarService $ics) {}

    public function handle(User $user, string $feedUrl, ?string $name = null, ?string $timezone = null): ConnectedAccount
    {
        $hash = hash('sha256', $feedUrl);

        if ($user->connectedAccounts()->where('feed_url_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['url' => 'You are already subscribed to this feed.']);
        }

        try {
            $remote = $this->ics->calendars($feedUrl)[0] ?? null;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['url' => 'Could not read an iCalendar feed at that URL.']);
        }

        $account = $user->connectedAccounts()->create([
            'provider' => ConnectedAccount::PROVIDER_ICS,
            'feed_url' => $feedUrl,
            'feed_url_hash' => $hash,
            'timezone' => $timezone,
            'display_name' => $name ?: ($remote['name'] ?? 'Subscribed calendar'),
            'is_active' => true,
            'sync_status' => 'idle',
        ]);

        SyncConnectedAccountJob::dispatch($account);

        return $account;
    }
}
