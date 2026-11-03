<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AgendaRequest extends FormRequest
{
    public const MAX_DAYS = 60;

    private const DEFAULT_DAYS = 7;

    private const INSTANT = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})?)?$/i';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'string', 'max:40'],
            'to' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (['from', 'to'] as $field) {
                if ($this->filled($field) && $this->instant($field) === null) {
                    $validator->errors()->add($field, "The {$field} must be an ISO 8601 date or date and time, for example 2026-07-20T09:00:00+02:00.");
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->range();

            if ($to->getTimestamp() <= $from->getTimestamp()) {
                $validator->errors()->add('to', 'The to must be after from.');
            } elseif ($to->getTimestamp() - $from->getTimestamp() > self::MAX_DAYS * 86400) {
                $validator->errors()->add('to', 'The range may not be longer than '.self::MAX_DAYS.' days.');
            }
        }];
    }

    /**
     * The requested window. A value without an offset is read in the user's
     * zone, and the defaults are the start of the user's today and a week on.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $from = $this->instant('from') ?? CarbonImmutable::now($this->timezone())->startOfDay();
        $to = $this->instant('to') ?? $from->addDays(self::DEFAULT_DAYS);

        return [$from, $to];
    }

    private function instant(string $field): ?CarbonImmutable
    {
        $value = $this->input($field);

        if (! is_string($value) || preg_match(self::INSTANT, $value, $parts) !== 1) {
            return null;
        }

        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];
        $hour = (int) ($parts[4] ?? 0);
        $minute = (int) ($parts[5] ?? 0);
        $second = (int) ($parts[6] ?? 0);

        if (! checkdate($month, $day, $year) || $year < 1 || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $zone = $this->timezone();

        if (($parts[7] ?? '') !== '') {
            $zone = $this->offset($parts[7]);

            if ($zone === null) {
                return null;
            }
        }

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, $zone);
    }

    private function offset(string $designator): ?string
    {
        if (strtoupper($designator) === 'Z') {
            return 'UTC';
        }

        $digits = str_replace(':', '', $designator);
        $hours = (int) substr($digits, 1, 2);
        $minutes = (int) substr($digits, 3, 2);

        if ($hours > 14 || $minutes > 59) {
            return null;
        }

        return sprintf('%s%02d:%02d', $digits[0], $hours, $minutes);
    }

    private function timezone(): string
    {
        $user = $this->user();

        return $user instanceof User ? $user->timezone : 'UTC';
    }
}
