<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PurgeDeletedEventsCommand extends Command
{
    protected $signature = 'chronos:purge-deleted-events {--days=30 : How long a deleted event stays recoverable}';

    protected $description = 'Permanently remove events deleted longer ago than the grace period';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = CarbonImmutable::now()->subDays($days);

        $expired = Event::onlyTrashed()->where('deleted_at', '<', $cutoff);

        // Counted before the delete rather than from its return value, which is
        // not typed as a count.
        $purged = $expired->count();
        $expired->forceDelete();

        $this->info("Purged {$purged} event(s) deleted before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
