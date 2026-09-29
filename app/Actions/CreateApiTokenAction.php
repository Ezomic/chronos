<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ApiAbility;
use App\Models\User;

class CreateApiTokenAction
{
    /**
     * Returns the plaintext token. It is the only time it exists in readable
     * form: only a hash is stored, so a caller that does not show it to the
     * user here has lost it.
     *
     * No expiry, as CHRON-54 decided: the consumers are unattended and have no
     * refresh flow. Revoking the token, or ID revoking the user's access, is
     * what ends it.
     *
     * @param  list<ApiAbility>  $abilities
     */
    public function handle(User $user, string $name, array $abilities, ?string $app): string
    {
        $granted = array_map(fn (ApiAbility $ability): string => $ability->value, $abilities);

        if ($app !== null) {
            $granted[] = 'app:'.$app;
        }

        return $user->createToken($name, $granted)->plainTextToken;
    }
}
