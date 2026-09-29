<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\ApiAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiTokenRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'list'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::enum(ApiAbility::class)],
            // The manage endpoints scope everything to the token's app and
            // refuse a token without one, so that ability is useless on its own.
            'app' => [
                Rule::requiredIf(fn (): bool => in_array(ApiAbility::EventsManage->value, $this->submittedAbilities(), true)),
                'nullable',
                'string',
                Rule::in(self::consumerApps()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'app.required' => 'Choose the app this token manages events for.',
        ];
    }

    public function tokenName(): string
    {
        return $this->string('name')->toString();
    }

    /**
     * @return list<ApiAbility>
     */
    public function abilities(): array
    {
        return array_map(ApiAbility::from(...), $this->submittedAbilities());
    }

    public function consumerApp(): ?string
    {
        return $this->filled('app') ? $this->string('app')->toString() : null;
    }

    /**
     * @return list<string>
     */
    public static function consumerApps(): array
    {
        $consumers = config('chronos.consumers');

        return array_map('strval', array_keys(is_array($consumers) ? $consumers : []));
    }

    /**
     * @return list<string>
     */
    private function submittedAbilities(): array
    {
        $abilities = $this->input('abilities');

        return is_array($abilities) ? array_values(array_filter($abilities, 'is_string')) : [];
    }
}
