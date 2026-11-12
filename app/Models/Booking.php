<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $booking_type_id
 * @property int|null $event_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $guest_name
 * @property string $guest_email
 * @property string|null $guest_phone
 * @property string|null $guest_note
 * @property string $guest_timezone
 * @property string $locale
 * @property string|null $slot_key
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'uuid',
    'booking_type_id',
    'event_id',
    'starts_at',
    'ends_at',
    'guest_name',
    'guest_email',
    'guest_phone',
    'guest_note',
    'guest_timezone',
    'locale',
    'slot_key',
    'cancelled_at',
])]
class Booking extends Model
{
    /**
     * @return BelongsTo<BookingType, $this>
     */
    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    /**
     * Withtrashed: cancelling soft-deletes the event, and the booking still
     * points at it.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function typeOrFail(): BookingType
    {
        return $this->bookingType()->firstOrFail();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
