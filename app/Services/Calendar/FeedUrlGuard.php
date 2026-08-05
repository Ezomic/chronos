<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Exceptions\UnsafeFeedUrlException;

/**
 * Keeps a subscribed ICS feed from reaching anything but the public internet.
 *
 * The feed URL is user-supplied and Chronos fetches it on a schedule, from a
 * droplet shared with every other app in the estate. Without this, a
 * subscription could read localhost, the other apps' ports, and the cloud
 * metadata endpoint.
 *
 * This resolves the host and rejects private, loopback, link-local and reserved
 * addresses. It cannot close the gap between resolving and connecting: a host
 * that answers publicly here and privately a moment later would still get
 * through. Pinning the connection to a checked address needs a curl-level
 * resolve override, which Laravel's client does not expose.
 */
class FeedUrlGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @throws UnsafeFeedUrlException
     */
    public function assertFetchable(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new UnsafeFeedUrlException('A calendar feed has to be an http or https URL.');
        }

        if (! is_string($host) || $host === '') {
            throw new UnsafeFeedUrlException('That URL has no host to fetch from.');
        }

        foreach ($this->resolver->resolve($host) as $address) {
            if (! $this->isPublic($address)) {
                throw new UnsafeFeedUrlException('That address is on a private network, so Chronos will not fetch it.');
            }
        }
    }

    public function isFetchable(string $url): bool
    {
        try {
            $this->assertFetchable($url);
        } catch (UnsafeFeedUrlException) {
            return false;
        }

        return true;
    }

    /**
     * A host that resolves to nothing is left alone: there is no request it
     * could reach, and the fetch itself will fail. What matters is a host that
     * resolves somewhere it should not.
     */
    private function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
