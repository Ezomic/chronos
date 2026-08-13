<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\Calendar;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCalendarRequest extends FormRequest
{
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
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', Rule::in(Calendar::COLOR_PALETTE)],
            // Reminders new events on this calendar start with.
            'default_reminder_minutes' => ['sometimes', 'array', 'max:5'],
            'default_reminder_minutes.*' => ['integer', Rule::in(Event::REMINDER_CHOICES)],
        ];
    }
}
