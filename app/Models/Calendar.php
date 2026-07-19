<?php

namespace App\Models;

use Database\Factories\CalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $connected_account_id
 * @property string $name
 * @property string $color
 * @property string $timezone
 * @property string|null $external_id
 * @property bool $is_default
 * @property bool $is_visible
 * @property bool $is_writable
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'connected_account_id',
    'name',
    'color',
    'timezone',
    'external_id',
    'is_default',
    'is_visible',
    'is_writable',
    'synced_at',
])]
class Calendar extends Model
{
    /** Distinguishing colors auto-assigned to new calendars. */
    public const COLOR_PALETTE = [
        '#3B82F6', '#10B981', '#F59E0B', '#EF4444',
        '#8B5CF6', '#EC4899', '#14B8A6', '#F97316',
    ];

    /** @use HasFactory<CalendarFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_visible' => 'boolean',
            'is_writable' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
