<?php

namespace App\Models;

use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $timezone
 * @property string|null $idp_id
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ObservedBy(UserObserver::class)]
#[Fillable(['name', 'email', 'timezone'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * Mirrors the column default, so a model that has not been read back from
     * the database still reports a timezone rather than null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'Europe/Amsterdam',
    ];

    /**
     * @return HasMany<Calendar, $this>
     */
    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    /**
     * @return HasMany<ConnectedAccount, $this>
     */
    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    /**
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * @return HasMany<EventTemplate, $this>
     */
    public function eventTemplates(): HasMany
    {
        return $this->hasMany(EventTemplate::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }
}
