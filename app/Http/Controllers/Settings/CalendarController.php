<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CreateCalendarAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCalendarRequest;
use App\Http\Requests\Settings\UpdateCalendarRequest;
use App\Models\Calendar;
use App\Models\ConnectedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    use InteractsWithCurrentUser;

    public function edit(): Response
    {
        $user = $this->currentUser();

        $calendars = $user->calendars()
            ->with('connectedAccount:id,provider,email_address')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Calendar $calendar) => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'color' => $calendar->color,
                'is_default' => $calendar->is_default,
                'is_writable' => $calendar->is_writable,
                'is_visible' => $calendar->is_visible,
                'provider' => $calendar->connectedAccount?->provider,
                'account_email' => $calendar->connectedAccount?->email_address,
            ])
            ->values();

        $accounts = $user->connectedAccounts()
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
            ->values();

        return Inertia::render('settings/Calendars', [
            'calendars' => $calendars,
            'palette' => Calendar::COLOR_PALETTE,
            'accounts' => $accounts,
        ]);
    }

    public function store(StoreCalendarRequest $request, CreateCalendarAction $action): RedirectResponse
    {
        $action->handle(
            $this->currentUser(),
            $request->string('name')->toString(),
            $request->string('color')->toString(),
        );

        return back()->with('status', 'Calendar created.');
    }

    public function update(UpdateCalendarRequest $request, Calendar $calendar): RedirectResponse
    {
        Gate::authorize('update', $calendar);

        $calendar->update($request->only('name', 'color'));

        return back()->with('status', 'Calendar updated.');
    }

    public function visibility(Request $request, Calendar $calendar): RedirectResponse
    {
        Gate::authorize('changeVisibility', $calendar);

        $calendar->update([
            'is_visible' => $request->boolean('is_visible'),
        ]);

        return back(fallback: route('calendars.edit'));
    }

    public function destroy(Calendar $calendar): RedirectResponse
    {
        Gate::authorize('delete', $calendar);

        // Cascades to the calendar's events.
        $calendar->delete();

        return back()->with('status', 'Calendar deleted.');
    }
}
