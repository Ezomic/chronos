<?php

return [

    'passkeys' => [

        /*
         * Chronos is passwordless (login is passkey / email code / SSO, and
         * users have no password), so Fortify's default of gating passkey
         * management behind the `password.confirm` middleware makes the
         * Security page a dead end ("Password confirmation required."). Managing
         * passkeys is protected by the authenticated session instead.
         */
        'confirmPassword' => false,

    ],

];
