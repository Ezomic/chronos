<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the calendar owner when a visitor cancels.
 */
class BookingCancelledMail extends BookingMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('booking.mail.cancelled.subject', ['name' => $this->booking->guest_name]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.cancelled',
            text: 'emails.booking.cancelled-text',
            with: [
                'name' => $this->booking->guest_name,
                'when' => $this->startsAtIn($this->booking->typeOrFail()->timezone),
            ],
        );
    }
}
