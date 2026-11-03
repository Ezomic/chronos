<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Actions\EnsureBookingTypeAction;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingAvailabilityRequest extends FormRequest
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
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.enabled' => ['required', 'boolean'],
            'days.*.start' => ['required', 'string', 'date_format:H:i'],
            'days.*.end' => ['required', 'string', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();
            $duration = $user instanceof User
                ? app(EnsureBookingTypeAction::class)->handle($user)->duration_minutes
                : 0;

            foreach ($this->array('days') as $index => $day) {
                if (! is_array($day) || ! $this->boolean("days.{$index}.enabled")) {
                    continue;
                }

                $minutes = $this->minutes($this->string("days.{$index}.end")->toString())
                    - $this->minutes($this->string("days.{$index}.start")->toString());

                if ($minutes < $duration) {
                    $validator->errors()->add("days.{$index}.end", "The closing time must be at least {$duration} minutes after the opening time.");
                }
            }
        }];
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
