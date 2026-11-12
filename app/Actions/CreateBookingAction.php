<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SlotUnavailableException;
use App\Mail\BookingConfirmedMail;
use App\Mail\BookingReceivedMail;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Calendar;
use App\Services\Booking\SlotFinder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CreateBookingAction
{
    public const CALENDAR_NAME = 'Bookings';

    public function __construct(
        private readonly SlotFinder $finder,
        private readonly CreateEventAction $createEvent,
    ) {}

    /**
     * @throws SlotUnavailableException
     */
    public function handle(
        BookingType $type,
        CarbonImmutable $startsAt,
        string $name,
        string $email,
        ?string $phone,
        ?string $note,
        string $timezone,
        string $locale,
    ): Booking {
        try {
            $booking = DB::transaction(fn (): Booking => $this->book($type, $startsAt, $name, $email, $phone, $note, $timezone, $locale));
        } catch (UniqueConstraintViolationException) {
            // Two visitors passed the check together; the unique slot key let
            // exactly one of them through.
            throw new SlotUnavailableException;
        }

        Mail::to($booking->guest_email)->locale($locale)->queue(new BookingConfirmedMail($booking));
        Mail::to($type->ownerOrFail()->email)->locale('en')->queue(new BookingReceivedMail($booking));

        return $booking;
    }

    private function book(
        BookingType $type,
        CarbonImmutable $startsAt,
        string $name,
        string $email,
        ?string $phone,
        ?string $note,
        string $timezone,
        string $locale,
    ): Booking {
        // A write first, so bookings queue up behind each other: the row lock
        // on MySQL, the database write lock on SQLite. Starting with a read
        // would let two SQLite transactions both pass the check and then fail
        // on upgrading to a write.
        BookingType::query()->whereKey($type->id)->update(['updated_at' => CarbonImmutable::now()]);

        if (! $type->is_active || ! $this->finder->isBookable($type, $startsAt)) {
            throw new SlotUnavailableException;
        }

        $endsAt = $startsAt->addMinutes($type->duration_minutes);

        $event = $this->createEvent->handle(
            calendar: $this->calendar($type),
            title: $type->name.': '.$name,
            startsAt: $startsAt,
            endsAt: $endsAt,
            allDay: false,
            timezone: $type->timezone,
            description: $this->description($email, $phone, $note),
        );

        return Booking::query()->create([
            'uuid' => (string) Str::uuid(),
            'booking_type_id' => $type->id,
            'event_id' => $event->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'guest_name' => $name,
            'guest_email' => $email,
            'guest_phone' => $phone,
            'guest_note' => $note,
            'guest_timezone' => $timezone,
            'locale' => $locale,
            'slot_key' => $type->id.':'.$startsAt->getTimestamp(),
        ]);
    }

    private function calendar(BookingType $type): Calendar
    {
        return Calendar::query()->firstOrCreate(
            ['user_id' => $type->user_id, 'name' => self::CALENDAR_NAME, 'connected_account_id' => null],
            [
                'color' => Calendar::COLOR_PALETTE[2],
                'timezone' => $type->timezone,
                'is_writable' => true,
                'is_visible' => true,
            ],
        );
    }

    private function description(string $email, ?string $phone, ?string $note): string
    {
        return implode("\n", array_filter([
            'Booked through the public booking page.',
            'Email: '.$email,
            $phone !== null ? 'Phone: '.$phone : null,
            $note !== null ? 'Note: '.$note : null,
        ]));
    }
}
