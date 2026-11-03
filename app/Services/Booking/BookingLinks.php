<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\Booking;
use Illuminate\Support\Facades\URL;

/**
 * The only way into a booking from outside. Each link is signed over its own
 * path, so a link for one booking, or for viewing, cannot be bent into
 * touching another booking or cancelling. They stop working when the call has
 * ended.
 */
class BookingLinks
{
    public function manage(Booking $booking): string
    {
        return $this->signed('book.manage', $booking);
    }

    public function cancel(Booking $booking): string
    {
        return $this->signed('book.cancel', $booking);
    }

    private function signed(string $route, Booking $booking): string
    {
        return URL::temporarySignedRoute($route, $booking->ends_at, ['booking' => $booking->uuid]);
    }
}
