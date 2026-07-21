<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\ValidatesWritableCalendar;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventTemplateRequest extends FormRequest
{
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
            'name' => ['required', 'string', 'max:60'],
            'calendar_id' => ['nullable', $this->writableCalendarRule()],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'all_day' => ['boolean'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'default_start_time' => ['nullable', 'date_format:H:i'],
            'frequency' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'reminder_minutes' => ['nullable', 'integer', Rule::in(Event::REMINDER_CHOICES)],
        ];
    }
}
