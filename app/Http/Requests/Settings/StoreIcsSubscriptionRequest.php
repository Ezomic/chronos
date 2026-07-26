<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreIcsSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $url = trim((string) $this->input('url'));

        // Calendar apps hand out webcal:// links; they're plain HTTP(S) feeds.
        if (Str::startsWith($url, 'webcal://')) {
            $url = 'https://'.Str::after($url, 'webcal://');
        }

        $this->merge(['url' => $url]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'name' => ['nullable', 'string', 'max:60'],
        ];
    }
}
