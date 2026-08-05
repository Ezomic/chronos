<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class IssueApiToken extends Command
{
    protected $signature = 'calendar:token
        {email}
        {--name=api}
        {--ability=* : Repeatable; defaults to events:create}
        {--app= : The consuming app this token speaks for, required to manage events}';

    protected $description = 'Mint a scoped API token for another app to create events';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $abilities = $this->abilities();
        $name = $this->option('name');

        $token = $user->createToken(is_string($name) ? $name : 'api', $abilities);

        $this->info("Token for {$user->email} (".implode(', ', $abilities).') — shown once:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function abilities(): array
    {
        $abilities = array_values(array_filter($this->option('ability'), 'is_string'));

        if ($abilities === []) {
            $abilities = ['events:create'];
        }

        $app = $this->option('app');

        if (is_string($app) && $app !== '') {
            $abilities[] = 'app:'.$app;
        }

        return $abilities;
    }
}
