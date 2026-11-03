<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Http\Request;

trait ResolvesBookingLocale
{
    /** @var array<int, string> */
    private const BOOKING_LOCALES = ['nl', 'en'];

    /**
     * ?lang wins, so the portfolio site can pass its own language along;
     * otherwise whatever the browser asks for, and English when it asks for
     * nothing we speak.
     */
    protected function bookingLocale(Request $request): string
    {
        $requested = $request->input('lang');

        if (is_string($requested) && in_array($requested, self::BOOKING_LOCALES, true)) {
            return $requested;
        }

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(substr($language, 0, 2));

            if (in_array($primary, self::BOOKING_LOCALES, true)) {
                return $primary;
            }
        }

        return 'en';
    }
}
