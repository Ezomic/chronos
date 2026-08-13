<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmIcsImportRequest extends FormRequest
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
            // Alphanumeric, so it cannot climb out of the upload directory when
            // it becomes a filename.
            'token' => ['required', 'string', 'alpha_num', 'size:40'],
            'calendar_id' => ['required', 'integer'],
        ];
    }
}
