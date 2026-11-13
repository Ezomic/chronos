<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendBookingWebhookJob;
use App\Models\Booking;
use Illuminate\Support\Facades\Bus;
use Throwable;

class QueueBookingWebhookAction
{
    /**
     * The booking is already saved when this runs, so nothing here may fail
     * the visitor's request: not a queue that cannot take the job, and not a
     * sync queue that runs it on the spot against a CMS that is down.
     */
    public function handle(Booking $booking): void
    {
        try {
            Bus::dispatch(new SendBookingWebhookJob($booking));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
