<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Booking;

enum BookingWebhookEvent: string
{
    case Created = 'booking.created';
    case Cancelled = 'booking.cancelled';

    /** The event that brings the CMS copy of this booking up to date. */
    public static function for(Booking $booking): self
    {
        return $booking->isCancelled() ? self::Cancelled : self::Created;
    }
}
