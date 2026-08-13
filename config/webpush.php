<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID
    |--------------------------------------------------------------------------
    |
    | The keypair that identifies this server to push services. Generate one
    | with `php artisan chronos:vapid-keys` and put it in the environment.
    | Without it, reminders fall back to mail.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://chronos.thijssensoftware.nl')),
        'publicKey' => env('VAPID_PUBLIC_KEY', ''),
        'privateKey' => env('VAPID_PRIVATE_KEY', ''),
    ],

];
