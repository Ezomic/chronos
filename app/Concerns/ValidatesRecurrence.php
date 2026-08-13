<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesRecurrence
{
    /** ICS weekday codes, indexed by Carbon's day of week (0 is Sunday). */
    public const WEEKDAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    /**
     * Rules for the recurrence half of an event form. Shared because creating
     * and updating an event ask for exactly the same thing.
     *
     * @return array<string, mixed>
     */
    protected function recurrenceRules(): array
    {
        return [
            'frequency' => ['nullable', Rule::in(['none', 'daily', 'weekly', 'monthly', 'yearly'])],
            'interval' => ['nullable', 'integer', 'min:1', 'max:99'],
            'byday' => ['nullable', 'array', 'max:7'],
            'byday.*' => [Rule::in(self::WEEKDAYS)],
            // Whether a monthly rule repeats on the date or on the weekday
            // position, "the 14th" against "the second Tuesday".
            'monthly_mode' => ['nullable', Rule::in(['day_of_month', 'weekday'])],
            'ends' => ['nullable', Rule::in(['never', 'until', 'count'])],
            'until' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'count' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];
    }
}
