<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SyncConnectedAccountAction;
use App\Models\ConnectedAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncConnectedAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public ConnectedAccount $account) {}

    public function handle(SyncConnectedAccountAction $action): void
    {
        $action->handle($this->account);
    }
}
