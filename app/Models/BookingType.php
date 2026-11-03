<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $slug
 * @property string $name
 * @property int $duration_minutes
 * @property int $slot_step_minutes
 * @property int $buffer_minutes
 * @property int $min_notice_minutes
 * @property int $max_days_ahead
 * @property string $timezone
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'slug',
    'name',
    'duration_minutes',
    'slot_step_minutes',
    'buffer_minutes',
    'min_notice_minutes',
    'max_days_ahead',
    'timezone',
    'is_active',
])]
class BookingType extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ownerOrFail(): User
    {
        return $this->user()->firstOrFail();
    }

    /**
     * @return HasMany<AvailabilityWindow, $this>
     */
    public function windows(): HasMany
    {
        return $this->hasMany(AvailabilityWindow::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
