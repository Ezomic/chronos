<?php

namespace App\Models;

use Database\Factories\ConnectedAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $email_address
 * @property string|null $display_name
 * @property string|null $oauth_access_token
 * @property string|null $oauth_refresh_token
 * @property Carbon|null $oauth_expires_at
 * @property string $sync_status
 * @property Carbon|null $sync_status_since
 * @property string|null $sync_error
 * @property Carbon|null $last_synced_at
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'provider',
    'email_address',
    'display_name',
    'oauth_access_token',
    'oauth_refresh_token',
    'oauth_expires_at',
    'sync_status',
    'sync_status_since',
    'sync_error',
    'last_synced_at',
    'is_active',
])]
#[Hidden(['oauth_access_token', 'oauth_refresh_token'])]
class ConnectedAccount extends Model
{
    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_MICROSOFT = 'microsoft';

    /** @use HasFactory<ConnectedAccountFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tokenIsExpired(): bool
    {
        return $this->oauth_expires_at === null
            || $this->oauth_expires_at->subMinute()->isPast();
    }

    /**
     * @return HasMany<Calendar, $this>
     */
    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_expires_at' => 'datetime',
            'sync_status_since' => 'datetime',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
