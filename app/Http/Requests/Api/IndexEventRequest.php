<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class IndexEventRequest extends FormRequest
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
            'source' => ['sometimes', 'array'],
            'source.type' => ['sometimes', 'string', 'max:40'],
            'source.id' => ['sometimes', 'string', 'max:64'],
            // Everything the calling app's events have done since this moment,
            // deletions included.
            'changed_since' => ['sometimes', 'date'],
        ];
    }
}
