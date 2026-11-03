<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

abstract class BookingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    /**
     * The call's start in a zone, in the language this mail is going out in.
     */
    protected function startsAtIn(string $timezone): string
    {
        return $this->booking->starts_at
            ->setTimezone($timezone)
            ->isoFormat('dddd D MMMM YYYY, HH:mm').' ('.$timezone.')';
    }
}
