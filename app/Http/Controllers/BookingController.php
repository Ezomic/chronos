<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CancelBookingAction;
use App\Actions\CreateBookingAction;
use App\Actions\EnsureBookingTypeAction;
use App\Concerns\ResolvesBookingLocale;
use App\Exceptions\SlotUnavailableException;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use App\Services\Booking\BookingLinks;
use App\Services\Booking\SlotFinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public booking page. Nothing here knows who the visitor is, and nothing
 * it sends back says anything about the owner's calendar beyond which start
 * times are open.
 */
class BookingController extends Controller
{
    use ResolvesBookingLocale;

    private const WIRE_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function index(Request $request, EnsureBookingTypeAction $ensure, SlotFinder $finder): Response
    {
        $type = $this->type($ensure);
        $locale = $this->bookingLocale($request);

        return Inertia::render('book/Index', [
            'locale' => $locale,
            'copy' => trans('booking.ui', [], $locale),
            'type' => ['name' => $type->name, 'duration_minutes' => $type->duration_minutes],
            'slots' => $finder->slots($type)->map(fn ($slot) => $slot->format(self::WIRE_FORMAT))->all(),
        ]);
    }

    public function store(
        StoreBookingRequest $request,
        EnsureBookingTypeAction $ensure,
        CreateBookingAction $create,
        BookingLinks $links,
    ): RedirectResponse {
        $locale = $this->bookingLocale($request);

        try {
            $booking = $create->handle(
                type: $this->type($ensure),
                startsAt: $request->startsAt(),
                name: $request->string('name')->toString(),
                email: $request->string('email')->toString(),
                note: $request->note(),
                timezone: $request->string('timezone')->toString(),
                locale: $locale,
            );
        } catch (SlotUnavailableException) {
            throw ValidationException::withMessages([
                'starts_at' => trans('booking.errors.starts_at', [], $locale),
            ]);
        }

        return redirect()->to($links->manage($booking));
    }

    public function manage(string $booking, BookingLinks $links): Response
    {
        $booking = $this->booking($booking);
        $locale = $booking->locale;
        $cancelled = $booking->isCancelled();

        return Inertia::render('book/Manage', [
            'locale' => $locale,
            'copy' => trans('booking.ui', [], $locale),
            'booking' => [
                'name' => $booking->guest_name,
                'email' => $booking->guest_email,
                'type' => $booking->typeOrFail()->name,
                'starts_at' => $booking->starts_at->format(self::WIRE_FORMAT),
                'timezone' => $booking->guest_timezone,
                'cancelled' => $cancelled,
            ],
            'cancel_url' => $cancelled ? null : $links->cancel($booking),
        ]);
    }

    public function cancel(string $booking, CancelBookingAction $action, BookingLinks $links): RedirectResponse
    {
        $booking = $this->booking($booking);

        $action->handle($booking);

        return redirect()->to($links->manage($booking));
    }

    /**
     * Looked up by hand rather than route-bound, so the signature check runs
     * first and a bad link answers the same whether or not the booking exists.
     */
    private function booking(string $uuid): Booking
    {
        return Booking::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function type(EnsureBookingTypeAction $ensure): BookingType
    {
        $owner = User::query()->oldest('id')->firstOrFail();

        return $ensure->handle($owner);
    }
}
