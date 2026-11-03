<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Booking\BookingIcs;
use App\Services\Booking\BookingLinks;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the calendar owner when someone books.
 */
class BookingReceivedMail extends BookingMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('booking.mail.received.subject', ['name' => $this->booking->guest_name]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking.received',
            text: 'emails.booking.received-text',
            with: [
                'name' => $this->booking->guest_name,
                'email' => $this->booking->guest_email,
                'note' => $this->booking->guest_note,
                'when' => $this->startsAtIn($this->booking->typeOrFail()->timezone),
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
            Attachment::fromData(fn () => app(BookingIcs::class)->write($this->booking, forVisitor: false), 'booking.ics')
                ->withMime('text/calendar'),
        ];
    }
}
