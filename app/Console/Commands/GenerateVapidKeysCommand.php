<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'chronos:vapid-keys';

    protected $description = 'Generate a VAPID keypair for web push reminders';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $public = $keys['publicKey'] ?? null;
        $private = $keys['privateKey'] ?? null;

        if (! is_string($public) || ! is_string($private)) {
            $this->error('The web push library did not return a usable keypair.');

            return self::FAILURE;
        }

        $this->info('Add these to the environment. The private key is a secret.');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$public);
        $this->line('VAPID_PRIVATE_KEY='.$private);

        return self::SUCCESS;
    }
}
