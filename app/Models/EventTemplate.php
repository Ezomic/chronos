<?php

namespace App\Models;

use Database\Factories\EventTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $calendar_id
 * @property string $name
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property bool $all_day
 * @property int $duration_minutes
 * @property string|null $default_start_time
 * @property string|null $frequency
 * @property int|null $reminder_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'calendar_id',
    'name',
    'title',
    'description',
    'location',
    'all_day',
    'duration_minutes',
    'default_start_time',
    'frequency',
    'reminder_minutes',
])]
class EventTemplate extends Model
{
    /** @use HasFactory<EventTemplateFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'all_day' => 'boolean',
            'duration_minutes' => 'integer',
            'reminder_minutes' => 'integer',
        ];
    }
}
