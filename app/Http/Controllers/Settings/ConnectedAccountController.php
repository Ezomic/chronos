<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\SubscribeToIcsFeedAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreIcsSubscriptionRequest;
use App\Jobs\SyncConnectedAccountJob;
use App\Models\ConnectedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConnectedAccountController extends Controller
{
    use InteractsWithCurrentUser;

    public function storeSubscription(StoreIcsSubscriptionRequest $request, SubscribeToIcsFeedAction $action): RedirectResponse
    {
        $action->handle(
            $this->currentUser(),
            $request->string('url')->toString(),
            $request->filled('name') ? $request->string('name')->toString() : null,
            $request->filled('timezone') ? $request->string('timezone')->toString() : null,
        );

        return back()->with('status', 'Calendar subscription added.');
    }

    public function resync(Request $request, ConnectedAccount $account): RedirectResponse
    {
        abort_unless($account->user_id === $this->currentUser()->id, 403);

        SyncConnectedAccountJob::dispatch($account);

        return back()->with('status', 'Sync started.');
    }

    public function destroy(Request $request, ConnectedAccount $account): RedirectResponse
    {
        abort_unless($account->user_id === $this->currentUser()->id, 403);

        // Cascades to the account's mirrored calendars and their events.
        $account->delete();

        return back()->with('status', 'Account disconnected.');
    }
}
