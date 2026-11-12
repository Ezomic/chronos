<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\ResolvesBookingLocale;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    use ResolvesBookingLocale;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * 'website' is a honeypot: invisible to people, irresistible to
     * form-filling bots.
     *
     * @var array<string, array<int, string>>
     */
    private const RULES = [
        'starts_at' => ['required', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
        'name' => ['required', 'string', 'max:100', 'regex:/[\p{L}\p{N}]/u'],
        'email' => ['required', 'string', 'max:254', 'email:rfc'],
        'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ().\-]{6,30}$/'],
        'note' => ['nullable', 'string', 'max:300', 'not_regex:/[\r\n]/'],
        'timezone' => ['required', 'string', 'timezone:all'],
        'website' => ['prohibited'],
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return self::RULES;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (self::RULES as $field => $rules) {
            foreach ($rules as $rule) {
                $messages[$field.'.'.explode(':', $rule)[0]] = $this->message($field);
            }
        }

        return $messages;
    }

    private function message(string $field): string
    {
        $text = trans('booking.errors.'.$field, [], $this->bookingLocale($this));

        return is_string($text) ? $text : '';
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $this->string('starts_at')->toString(), 'UTC')
            ?: CarbonImmutable::now();
    }

    /**
     * The note, or nothing when it says nothing: a lone "?" or "..." is not a
     * message worth keeping.
     */
    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && preg_match('/[\p{L}\p{N}]/u', $note) === 1 ? trim($note) : null;
    }

    public function phone(): ?string
    {
        $phone = $this->input('phone');

        return is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone)) {
            $this->merge(['phone' => trim($phone)]);
        }

        $name = $this->input('name');

        if (is_string($name)) {
            // One line, so a name can never smuggle a line break into a mail header.
            $this->merge(['name' => trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $name))]);
        }
    }
}
