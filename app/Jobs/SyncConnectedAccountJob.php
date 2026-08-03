<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SyncConnectedAccountAction;
use App\Exceptions\ReauthorizationRequiredException;
use App\Models\ConnectedAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncConnectedAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public ConnectedAccount $account) {}

    public function handle(SyncConnectedAccountAction $action): void
    {
        try {
            $action->handle($this->account);
        } catch (ReauthorizationRequiredException $e) {
            // Retrying can never fix this, and the scheduler would otherwise
            // re-dispatch every 15 minutes forever. Deactivating takes the
            // account out of calendar:sync; the OAuth callback switches it back
            // on when the user reconnects. The action has already recorded the
            // error against the account, which is what the UI surfaces, so this
            // is handled rather than a queue failure.
            $this->account->update(['is_active' => false]);

            Log::warning('Connected account deactivated, needs reconnecting.', [
                'account_id' => $this->account->id,
                'provider' => $this->account->provider,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
