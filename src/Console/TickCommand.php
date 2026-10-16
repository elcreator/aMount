<?php

namespace Elcreator\aMount\Console;

use Elcreator\aMount\Services\Runs;
use Illuminate\Console\Command;

/**
 * The once-a-minute heartbeat of the module: starts rules that are due, queues
 * abandoned runs again and trims old history. Scheduled by the provider.
 */
class TickCommand extends Command
{
    protected $signature = 'amount:tick';

    protected $description = 'Start due aMount rules, re-queue abandoned runs and purge old run history.';

    public function handle(): int
    {
        $result = (new Runs())->tick();

        $this->info(sprintf(
            '[amount:tick] started %d, skipped %d, re-queued %d, purged %d.',
            $result['started'],
            $result['skipped'],
            $result['reaped'],
            $result['purged']
        ));

        return self::SUCCESS;
    }
}
