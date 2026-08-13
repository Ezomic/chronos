<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\ValidatesRecurrence;
use App\Concerns\ValidatesWritableCalendar;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    use ValidatesRecurrence;
    use ValidatesWritableCalendar;

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
            'calendar_id' => ['required', $this->writableCalendarRule()],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'all_day' => ['boolean'],
            'timezone' => ['nullable', 'timezone:all'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', $this->boolean('all_day') ? 'after_or_equal:starts_at' : 'after:starts_at'],
            ...$this->recurrenceRules(),
            'reminders' => ['sometimes', 'array', 'max:5'],
            'reminders.*' => ['integer', Rule::in(Event::REMINDER_CHOICES)],
            // Editing one occurrence of a series rather than all of it; the
            // occurrence is named by the start the series generated for it.
            'scope' => ['nullable', Rule::in(['series', 'occurrence'])],
            'occurrence_starts_at' => ['nullable', 'required_if:scope,occurrence', 'date'],
        ];
    }
}
