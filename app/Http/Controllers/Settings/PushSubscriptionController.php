<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PushSubscriptionController extends Controller
{
    use InteractsWithCurrentUser;

    public function store(StorePushSubscriptionRequest $request): RedirectResponse
    {
        $endpoint = $request->string('endpoint')->toString();

        // A browser hands back the same endpoint when it re-subscribes, so this
        // is an update rather than a second device.
        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'user_id' => $this->currentUser()->id,
                'endpoint' => $endpoint,
                'public_key' => $request->string('public_key')->toString(),
                'auth_token' => $request->string('auth_token')->toString(),
                'device_label' => $request->filled('device_label')
                    ? $request->string('device_label')->toString()
                    : null,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notifications enabled on this device.')]);

        return back();
    }

    public function destroy(PushSubscription $pushSubscription): RedirectResponse
    {
        abort_unless($pushSubscription->user_id === $this->currentUser()->id, 403);

        $pushSubscription->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device removed.')]);

        return back();
    }
}
