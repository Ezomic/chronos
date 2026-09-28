<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

class RevokeApiTokenAction
{
    /**
     * Scoped to the user's own tokens rather than looked up by id alone, so a
     * guessed or borrowed id cannot revoke somebody else's token.
     */
    public function handle(User $user, int $tokenId): bool
    {
        return $user->tokens()->whereKey($tokenId)->delete() > 0;
    }
}
