<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int $minutes_before
 * @property Carbon|null $sent_at
 * @property Carbon|null $sent_for
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['event_id', 'minutes_before', 'sent_at', 'sent_for'])]
class EventReminder extends Model
{
    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes_before' => 'integer',
            'sent_at' => 'datetime',
            'sent_for' => 'datetime',
        ];
    }
}
