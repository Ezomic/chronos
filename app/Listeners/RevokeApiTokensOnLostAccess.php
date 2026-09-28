<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use LogicException;
use Thijssensoftware\IdClient\Events\AccessRevoked;

/**
 * Deletes every API token of a user when ID revokes their access to Chronos.
 *
 * A plain ID logout is deliberately not enough: ID sends one per session, and
 * signing out on one machine must not stop zero or tempo creating events.
 */
class RevokeApiTokensOnLostAccess
{
    public function handle(AccessRevoked $event): void
    {
        // Thrown rather than skipped: the failed delivery is reported and ID
        // retries it, where a silent return would leave the tokens working.
        if (! $event->user instanceof User) {
            throw new LogicException('id-client.user_model is not '.User::class.'.');
        }

        $event->user->tokens()->delete();
    }
}
