<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\EnsureBookingTypeAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBookingAvailabilityRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BookingSettingsController extends Controller
{
    use InteractsWithCurrentUser;

    public function edit(EnsureBookingTypeAction $ensure): Response
    {
        $type = $ensure->handle($this->currentUser());
        $windows = $type->windows()->get()->keyBy('weekday');

        $days = [];

        foreach (range(1, 7) as $weekday) {
            $window = $windows->get($weekday);

            $days[] = [
                'weekday' => $weekday,
                'enabled' => $window !== null,
                'start' => $window->start_time ?? '09:00',
                'end' => $window->end_time ?? '17:00',
            ];
        }

        return Inertia::render('settings/Booking', [
            'days' => $days,
            'timezone' => $type->timezone,
            'duration_minutes' => $type->duration_minutes,
            'url' => url('/book'),
        ]);
    }

    public function update(UpdateBookingAvailabilityRequest $request, EnsureBookingTypeAction $ensure): RedirectResponse
    {
        $type = $ensure->handle($this->currentUser());

        DB::transaction(function () use ($request, $type): void {
            $type->windows()->delete();

            foreach (array_keys($request->array('days')) as $index) {
                if ($request->boolean("days.{$index}.enabled")) {
                    $type->windows()->create([
                        'weekday' => $request->integer("days.{$index}.weekday"),
                        'start_time' => $request->string("days.{$index}.start")->toString(),
                        'end_time' => $request->string("days.{$index}.end")->toString(),
                    ]);
                }
            }
        });

        return back()->with('status', 'Booking hours saved.');
    }
}
