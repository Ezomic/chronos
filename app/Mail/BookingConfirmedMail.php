<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Booking\BookingIcs;
use App\Services\Booking\BookingLinks;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the visitor, in their language.
 */
class BookingConfirmedMail extends BookingMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('booking.mail.confirmed.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.confirmed',
            text: 'emails.booking.confirmed-text',
            with: [
                'name' => $this->booking->guest_name,
                'type' => $this->booking->typeOrFail()->name,
                'when' => $this->startsAtIn($this->booking->guest_timezone),
                'manageUrl' => app(BookingLinks::class)->manage($this->booking),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => app(BookingIcs::class)->write($this->booking, forVisitor: true), 'invite.ics')
                ->withMime('text/calendar'),
        ];
    }
}
