<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Exceptions\UnsafeFeedUrlException;
use App\Services\Calendar\FeedUrlGuard;
use Closure;
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
        $url = trim($this->string('url')->toString());

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
            'url' => [
                'required',
                'string',
                'url:http,https',
                'max:2048',
                // Checked again at fetch time, but rejecting it here tells the
                // user why instead of leaving them with a failed subscription.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! is_string($value)) {
                        return;
                    }

                    try {
                        app(FeedUrlGuard::class)->assertFetchable($value);
                    } catch (UnsafeFeedUrlException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
            'name' => ['nullable', 'string', 'max:60'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ];
    }
}
