<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * Resolves a hostname to every address it points at. Injectable so tests can
 * decide what a host resolves to instead of depending on the machine's DNS,
 * which differs between a Herd laptop (where .test answers 127.0.0.1) and CI.
 */
class HostResolver
{
    /**
     * @return array<int, string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host);
        $addresses = $addresses === false ? [] : $addresses;

        $records = @dns_get_record($host, DNS_AAAA);

        foreach ($records === false ? [] : $records as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }
}
