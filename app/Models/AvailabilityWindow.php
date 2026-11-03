<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $booking_type_id
 * @property int $weekday
 * @property string $start_time
 * @property string $end_time
 */
#[Fillable(['booking_type_id', 'weekday', 'start_time', 'end_time'])]
class AvailabilityWindow extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }
}
