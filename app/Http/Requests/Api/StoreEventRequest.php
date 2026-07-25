<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', $this->boolean('all_day') ? 'after_or_equal:starts_at' : 'after:starts_at'],
            'all_day' => ['boolean'],
            'timezone' => ['nullable', 'timezone:all'],

            'source' => ['nullable', 'array'],
            // Only known apps: keeps source_url from becoming an open redirect
            // when the calendar later renders it as a link.
            'source.app' => ['required_with:source', 'string', Rule::in(['zero', 'tracker', 'tempo'])],
            'source.type' => ['required_with:source', 'string', 'max:40'],
            'source.id' => ['required_with:source', 'string', 'max:64'],
            'source.url' => ['required_with:source', 'url', 'max:2048'],
        ];
    }
}
