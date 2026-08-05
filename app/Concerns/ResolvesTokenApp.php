<?php

declare(strict_types=1);

namespace App\Concerns;

trait ResolvesTokenApp
{
    use InteractsWithCurrentUser;

    /**
     * The consuming app a token was issued for, carried as an `app:{name}`
     * ability. Abilities say what a token may do; this says on whose behalf,
     * which is what scopes the manage endpoints to a single app's own events.
     *
     * Null for the original tokens, which were minted before app scoping and
     * carry events:create alone.
     */
    protected function tokenApp(): ?string
    {
        // Session-authenticated requests carry a TransientToken, which answers
        // yes to every ability. App scope has to come from a real bearer token
        // or a logged-in browser would be able to speak for a consuming app.
        if (request()->bearerToken() === null) {
            return null;
        }

        $abilities = $this->currentUser()->currentAccessToken()->abilities ?? [];

        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'app:')) {
                return substr($ability, 4);
            }
        }

        return null;
    }
}
