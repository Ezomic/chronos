<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreIcsImportRequest extends FormRequest
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
            // 2 MB is far more than a calendar invite and far less than a
            // problem. Extensions are checked loosely because exporters send
            // text/calendar, application/octet-stream and everything between.
            'file' => ['required', 'file', 'max:2048', 'extensions:ics,ical,ifb,txt'],
        ];
    }
}
