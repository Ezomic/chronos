<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectedAccountController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Calendars', [
            'accounts' => $request->user()->connectedAccounts()
                ->latest()
                ->get()
                ->map(fn (ConnectedAccount $account) => [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'email' => $account->email_address,
                    'display_name' => $account->display_name,
                    'sync_status' => $account->sync_status,
                    'last_synced_at_diff' => $account->last_synced_at?->diffForHumans(),
                ])
                ->values(),
        ]);
    }

    public function destroy(Request $request, ConnectedAccount $account): RedirectResponse
    {
        abort_unless($account->user_id === $request->user()->id, 403);

        // Cascades to the account's mirrored calendars and their events.
        $account->delete();

        return back()->with('status', 'Account disconnected.');
    }
}
