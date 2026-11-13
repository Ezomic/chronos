<?php

declare(strict_types=1);

namespace App\Actions;

use App\Mail\BookingCancelledMail;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CancelBookingAction
{
    public function __construct(private readonly QueueBookingWebhookAction $queueWebhook) {}

    /**
     * Cancels the booking, frees its slot and removes its event. A booking
     * that is already cancelled is left alone, so a second click or a retried
     * request does not notify the owner twice.
     */
    public function handle(Booking $booking): bool
    {
        $cancelled = DB::transaction(function () use ($booking): bool {
            $claimed = Booking::query()
                ->whereKey($booking->id)
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => CarbonImmutable::now(), 'slot_key' => null]);

            if ($claimed === 0) {
                return false;
            }

            $booking->event?->delete();

            return true;
        });

        if ($cancelled) {
            $booking->refresh();

            $this->queueWebhook->handle($booking);

            Mail::to($booking->typeOrFail()->ownerOrFail()->email)->locale('en')->queue(new BookingCancelledMail($booking));
        }

        return $cancelled;
    }
}
