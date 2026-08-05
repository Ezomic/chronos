<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Concerns\InteractsWithCurrentUser;
use App\Models\Calendar;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    use InteractsWithCurrentUser;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The calendar this request asks for by id or by name, or null when it did
     * not ask. Resolving here rather than in the controller keeps the lookup
     * and the rule that validates it in one place.
     */
    public function targetCalendar(): ?Calendar
    {
        $value = $this->input('calendar');

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        if ($value === '') {
            return null;
        }

        if (ctype_digit((string) $value)) {
            $byId = $this->writableCalendars()->whereKey((int) $value)->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        return $this->writableCalendars()->where('name', (string) $value)->first();
    }

    /**
     * @return array<int, string>
     */
    private function consumerApps(): array
    {
        $consumers = config('chronos.consumers');

        return array_keys(is_array($consumers) ? $consumers : []);
    }

    /**
     * @return Builder<Calendar>
     */
    private function writableCalendars(): Builder
    {
        return Calendar::query()
            ->where('user_id', $this->currentUser()->id)
            ->where('is_writable', true)
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A consuming app can route its events: zero's mail into one
            // calendar, tempo's training into another.
            'calendar' => ['sometimes', 'nullable', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && $value !== '' && $this->targetCalendar() === null) {
                    $fail('The selected calendar is not one of your writable calendars.');
                }
            }],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', $this->boolean('all_day') ? 'after_or_equal:starts_at' : 'after:starts_at'],
            'all_day' => ['boolean'],
            'timezone' => ['nullable', 'timezone:all'],

            'source' => ['nullable', 'array'],
            // Only known apps: keeps source_url from becoming an open redirect
            // when the calendar later renders it as a link. Onboarding another
            // consumer is a config change, not a code change.
            'source.app' => ['required_with:source', 'string', Rule::in($this->consumerApps())],
            'source.type' => ['required_with:source', 'string', 'max:40'],
            'source.id' => ['required_with:source', 'string', 'max:64'],
            'source.url' => ['required_with:source', 'url', 'max:2048'],
        ];
    }
}
