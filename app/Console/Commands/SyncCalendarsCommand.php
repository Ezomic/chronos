<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncConnectedAccountJob;
use App\Models\ConnectedAccount;
use Illuminate\Console\Command;

class SyncCalendarsCommand extends Command
{
    protected $signature = 'calendar:sync {--account= : Only sync this connected account id}';

    protected $description = 'Mirror events from every active connected calendar account';

    public function handle(): int
    {
        ConnectedAccount::query()
            ->where('is_active', true)
            ->when($this->option('account'), fn ($query) => $query->whereKey($this->option('account')))
            ->get()
            ->each(fn (ConnectedAccount $account) => SyncConnectedAccountJob::dispatch($account));

        return self::SUCCESS;
    }
}
