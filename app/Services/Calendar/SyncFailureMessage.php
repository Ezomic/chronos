<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Exceptions\ReauthorizationRequiredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Turns a sync failure into a message that is safe to store and show.
 *
 * Raw exception messages cannot be used for this. A connection error carries
 * the full request URL, and an ICS feed URL is itself a bearer credential (it
 * is encrypted at rest for exactly that reason); a failed token exchange
 * carries the provider's raw response body. Both would land unencrypted in
 * connected_accounts.sync_error and be rendered in Settings.
 *
 * Only messages Chronos authored, and provider status codes, come through.
 */
class SyncFailureMessage
{
    public function for(Throwable $e): string
    {
        return match (true) {
            // Our own wording, written to be shown to the user.
            $e instanceof ReauthorizationRequiredException => $e->getMessage(),
            $e instanceof RequestException => $this->forStatus($e->response->status()),
            $e instanceof ConnectionException => 'Could not reach the calendar provider. The next scheduled sync will retry.',
            default => 'Syncing failed unexpectedly. The details are in the application log.',
        };
    }

    private function forStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'The provider refused our access. Reconnect the account to resume syncing.',
            $status === 404 => 'The calendar is no longer available at that address.',
            $status === 429 => 'The provider is rate limiting us. The next scheduled sync will retry.',
            $status >= 500 => 'The calendar provider returned an error. The next scheduled sync will retry.',
            default => "The calendar provider rejected the request (HTTP {$status}).",
        };
    }
}
