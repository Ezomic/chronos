<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CodeVerification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and verifies short-lived, single-use numeric codes stored in the cache.
 * The caller supplies the full cache key so different flows (login, re-auth)
 * keep their codes separate while sharing one implementation.
 */
class OneTimeCodeService
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function issue(string $key): string
    {
        $code = (string) random_int(100000, 999999);

        Cache::put($key, ['hash' => Hash::make($code), 'attempts' => 0], now()->addMinutes(self::TTL_MINUTES));

        return $code;
    }

    public function verify(string $key, string $code): CodeVerification
    {
        $entry = Cache::get($key);

        if ($entry === null || $entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return CodeVerification::Expired;
        }

        if (! Hash::check($code, $entry['hash'])) {
            Cache::put($key, [...$entry, 'attempts' => $entry['attempts'] + 1], now()->addMinutes(self::TTL_MINUTES));

            return CodeVerification::Incorrect;
        }

        Cache::forget($key);

        return CodeVerification::Valid;
    }
}
