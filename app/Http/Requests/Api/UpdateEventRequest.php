<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is optional: a consuming app patches what changed. Times move
     * as a pair, since a start without its end cannot be resolved.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'all_day' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'starts_at' => ['required_with:ends_at', 'date'],
            'ends_at' => [
                'required_with:starts_at',
                'date',
                $this->boolean('all_day') ? 'after_or_equal:starts_at' : 'after:starts_at',
            ],
        ];
    }
}
