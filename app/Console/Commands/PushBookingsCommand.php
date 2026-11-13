<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingWebhookEvent;
use App\Models\Booking;
use App\Services\Cms\BookingWebhookClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;

class PushBookingsCommand extends Command
{
    protected $signature = 'chronos:push-bookings
        {booking?* : Booking ids or uuids}
        {--all : Send every booking}';

    protected $description = 'Send bookings to the CMS again: booking.created, or booking.cancelled for a cancelled one';

    public function handle(BookingWebhookClient $client): int
    {
        $keys = array_values(array_filter((array) $this->argument('booking'), 'is_string'));
        $all = (bool) $this->option('all');

        if ($all === ($keys !== [])) {
            $this->error('Give one or more booking ids or uuids, or --all, not both.');

            return self::FAILURE;
        }

        if (! $client->configured()) {
            $this->error('CMS_WEBHOOK_URL and CMS_WEBHOOK_SECRET must both be set. Nothing sent.');

            return self::FAILURE;
        }

        $bookings = $all ? Booking::query()->oldest('id')->get() : $this->find($keys);

        if ($bookings === null) {
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($bookings as $booking) {
            $failed += $this->push($client, $booking) ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * All or nothing: one mistyped id sends none of them, so a backfill is
     * never half done by accident.
     *
     * @param  array<int, string>  $keys
     * @return Collection<int, Booking>|null
     */
    private function find(array $keys): ?Collection
    {
        $bookings = new Collection;
        $unknown = [];

        foreach ($keys as $key) {
            $booking = Booking::query()->where(ctype_digit($key) ? 'id' : 'uuid', $key)->first();

            if ($booking === null) {
                $unknown[] = $key;

                continue;
            }

            $bookings->put($booking->id, $booking);
        }

        if ($unknown !== []) {
            $this->error('No booking found for: '.implode(', ', $unknown).'. Nothing sent.');

            return null;
        }

        return $bookings->values();
    }

    private function push(BookingWebhookClient $client, Booking $booking): bool
    {
        $event = BookingWebhookEvent::for($booking);

        try {
            $client->send($booking, $event);
        } catch (RequestException $e) {
            $this->error("Failed {$event->value} {$booking->uuid}: the CMS answered {$e->response->status()}.");

            return false;
        } catch (ConnectionException $e) {
            $this->error("Failed {$event->value} {$booking->uuid}: {$e->getMessage()}");

            return false;
        }

        $this->info("Sent {$event->value} {$booking->uuid} ({$booking->guest_name}).");

        return true;
    }
}
