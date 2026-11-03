<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\Booking;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

class BookingIcs
{
    public function __construct(private readonly BookingLinks $links) {}

    /**
     * The invitation the visitor gets, or the owner's copy of it.
     */
    public function write(Booking $booking, bool $forVisitor): string
    {
        $type = $booking->typeOrFail();
        $owner = $type->ownerOrFail();

        $document = new VCalendar([
            'PRODID' => '-//Thijssensoftware//Chronos//EN',
            'VERSION' => '2.0',
            'CALSCALE' => 'GREGORIAN',
        ]);

        $vevent = $document->add('VEVENT', [
            'UID' => 'booking-'.$booking->uuid.'@'.$this->host(),
            'SUMMARY' => $forVisitor ? $type->name.' with '.$owner->name : $type->name.': '.$booking->guest_name,
            'DTSTAMP' => $booking->created_at?->utc()->toDateTime() ?? now()->utc()->toDateTime(),
            'DTSTART' => $booking->starts_at->utc()->toDateTime(),
            'DTEND' => $booking->ends_at->utc()->toDateTime(),
        ]);

        if ($vevent instanceof VEvent) {
            $vevent->add('ORGANIZER', 'mailto:'.$owner->email, ['CN' => $owner->name]);
            $vevent->add('ATTENDEE', 'mailto:'.$booking->guest_email, ['CN' => $booking->guest_name]);
            $vevent->add('DESCRIPTION', 'Manage or cancel: '.$this->links->manage($booking));
        }

        return $document->serialize();
    }

    private function host(): string
    {
        $appUrl = config('app.url');
        $host = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;

        return is_string($host) ? $host : 'chronos';
    }
}
