<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
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
            'calendar_id' => [
                'required',
                Rule::exists('calendars', 'id')->where(fn ($query) => $query
                    ->where('user_id', $this->user()->id)
                    ->where('is_writable', true)),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'all_day' => ['boolean'],
            'timezone' => ['nullable', 'timezone:all'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', $this->boolean('all_day') ? 'after_or_equal:starts_at' : 'after:starts_at'],
            'frequency' => ['nullable', Rule::in(['none', 'daily', 'weekly', 'monthly', 'yearly'])],
            'until' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
