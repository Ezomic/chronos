<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class IssueApiToken extends Command
{
    protected $signature = 'calendar:token {email} {--name=api} {--ability=events:create}';

    protected $description = 'Mint a scoped API token for another app to create events';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $name = $this->option('name');

        $token = $user->createToken(is_string($name) ? $name : 'api', [$this->option('ability')]);

        $this->info("Token for {$user->email} (ability: {$this->option('ability')}) — shown once:");
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
