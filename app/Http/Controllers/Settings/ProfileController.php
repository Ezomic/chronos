<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use DateTimeZone;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    use InteractsWithCurrentUser;

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'timezones' => DateTimeZone::listIdentifiers(),
            // Reminders go to these devices when there are any, and to email
            // when there are none.
            'vapidPublicKey' => config('webpush.vapid.publicKey'),
            'pushDevices' => $this->currentUser()->pushSubscriptions()
                ->latest()
                ->get()
                ->map(fn ($device) => [
                    'id' => $device->id,
                    'label' => $device->device_label ?? 'Unnamed device',
                    'added_at_diff' => $device->created_at?->diffForHumans() ?? '',
                    'last_used_at_diff' => $device->last_used_at?->diffForHumans(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $this->currentUser()->fill($request->validated());

        if ($this->currentUser()->isDirty('email')) {
            $this->currentUser()->email_verified_at = null;
        }

        $this->currentUser()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $this->currentUser();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
