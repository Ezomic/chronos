<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BookingWebhookEvent;
use App\Models\Booking;
use App\Services\Cms\BookingWebhookClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBookingWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    /**
     * One policy for every failure, a 401 or 422 included: a wrong secret or
     * a CMS that is mid-deploy gets fixed within the day, and the job then
     * delivers by itself. Seven waits, a little over 22 hours in total.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900, 3600, 10800, 21600, 43200];

    public function __construct(public Booking $booking) {}

    /**
     * The booking is loaded again when the job runs, and the event follows
     * from what it is then. A try that lands after the visitor has cancelled
     * says cancelled, so a late delivery can never bring a booking back.
     */
    public function handle(BookingWebhookClient $client): void
    {
        $client->send($this->booking, BookingWebhookEvent::for($this->booking));
    }
}
