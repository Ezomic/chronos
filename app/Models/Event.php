<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $calendar_id
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $all_day
 * @property string $timezone
 * @property string|null $rrule
 * @property int|null $overrides_event_id
 * @property Carbon|null $overrides_starts_at
 * @property array<int, string>|null $excluded_dates
 * @property int|null $reminder_minutes
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $reminder_sent_for
 * @property string|null $external_id
 * @property string|null $external_etag
 * @property string|null $source_app
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $source_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'calendar_id',
    'title',
    'description',
    'location',
    'starts_at',
    'ends_at',
    'all_day',
    'timezone',
    'rrule',
    'overrides_event_id',
    'overrides_starts_at',
    'excluded_dates',
    'reminder_minutes',
    'reminder_sent_at',
    'reminder_sent_for',
    'external_id',
    'external_etag',
    'source_app',
    'source_type',
    'source_id',
    'source_url',
])]
class Event extends Model
{
    /** Minutes-before-start a reminder may be set to. */
    public const REMINDER_CHOICES = [0, 5, 10, 15, 30, 60, 120, 1440];

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /**
     * The series this event replaces one occurrence of.
     *
     * @return BelongsTo<Event, $this>
     */
    public function overriddenSeries(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'overrides_event_id');
    }

    /**
     * Occurrences of this series that were edited into events of their own.
     *
     * @return HasMany<Event, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(Event::class, 'overrides_event_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'overrides_starts_at' => 'datetime',
            'excluded_dates' => 'array',
            'reminder_minutes' => 'integer',
            'reminder_sent_at' => 'datetime',
            'reminder_sent_for' => 'datetime',
        ];
    }
}
