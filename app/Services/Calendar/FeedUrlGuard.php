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
 * This resolves the host and rejects private, loopback, link-local, reserved and
 * carrier-grade NAT addresses, and a host that resolves to nothing at all. The
 * addresses it checked are returned so the fetch can pin its connection to
 * them; otherwise a host that answers publicly here and privately a moment
 * later would still get through.
 */
class FeedUrlGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return non-empty-array<int, string> every address the host resolved to, all public
     *
     * @throws UnsafeFeedUrlException
     */
    public function assertFetchable(string $url): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new UnsafeFeedUrlException('A calendar feed has to be an http or https URL.');
        }

        if (! is_string($host) || $host === '') {
            throw new UnsafeFeedUrlException('That URL has no host to fetch from.');
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new UnsafeFeedUrlException('That host does not resolve to any address, so Chronos cannot check it is safe to fetch.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw new UnsafeFeedUrlException('That address is on a private network, so Chronos will not fetch it.');
            }
        }

        return $addresses;
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
     * PHP's private and reserved ranges leave out 100.64.0.0/10, the carrier-grade
     * NAT range Tailscale hands out, so that one is checked by hand.
     */
    private function isPublic(string $address): bool
    {
        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        $long = ip2long($address);

        return $public && ($long === false || ($long & 0xFFC00000) !== 0x64400000);
    }
}
