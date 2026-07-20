<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\Calendar;
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
        ];
    }
}
