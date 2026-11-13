<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\BookingWebhookEvent;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Pushes a booking to the portfolio CMS, which keeps a copy for its admin.
 * Chronos stays the source of truth: the CMS upserts by uuid, so sending the
 * same booking again is safe.
 */
class BookingWebhookClient
{
    private const PATH = '/webhooks/chronos/bookings';

    private const WIRE_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function configured(): bool
    {
        return $this->baseUrl() !== '' && $this->secret() !== '';
    }

    /**
     * Sends nothing when the webhook is not configured: a request is never
     * sent unsigned.
     *
     * @throws RequestException when the CMS answers anything but a 2xx
     * @throws ConnectionException
     */
    public function send(Booking $booking, BookingWebhookEvent $event): void
    {
        if (! $this->configured()) {
            return;
        }

        // Encoded once: the signature covers these exact bytes, so the same
        // string has to be what goes over the wire.
        $body = json_encode(
            ['event' => $event->value, 'booking' => $this->payload($booking)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        // Taken here rather than when the job was queued, so a retry hours
        // later still falls inside the window the CMS accepts.
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();

        $response = Http::withBody($body, 'application/json')
            ->withHeaders([
                'X-Chronos-Timestamp' => $timestamp,
                'X-Chronos-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $this->secret()),
            ])
            ->connectTimeout(5)
            ->timeout(10)
            // A signed booking is for the CMS only, never for wherever it
            // might point us.
            ->withoutRedirecting()
            ->post(rtrim($this->baseUrl(), '/').self::PATH);

        if (! $response->successful()) {
            throw new RequestException($response);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function payload(Booking $booking): array
    {
        return [
            'uuid' => $booking->uuid,
            'type' => $booking->typeOrFail()->name,
            'starts_at' => $this->utc($booking->starts_at),
            'ends_at' => $this->utc($booking->ends_at),
            'guest_name' => $booking->guest_name,
            'guest_email' => $booking->guest_email,
            'guest_phone' => $booking->guest_phone,
            'guest_note' => $booking->guest_note,
            'guest_timezone' => $booking->guest_timezone,
            'locale' => $booking->locale,
            'cancelled_at' => $this->utc($booking->cancelled_at),
            'created_at' => $this->utc($booking->created_at),
        ];
    }

    private function utc(?DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->utc()->format(self::WIRE_FORMAT);
    }

    private function baseUrl(): string
    {
        $url = config('services.cms.webhook_url');

        return is_string($url) ? trim($url) : '';
    }

    private function secret(): string
    {
        $secret = config('services.cms.webhook_secret');

        return is_string($secret) ? $secret : '';
    }
}
