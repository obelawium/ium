<?php

declare(strict_types=1);

namespace Obelaw\Ium\Console\Commands;

use Illuminate\Console\Command;
use Obelaw\Ium\Outbox\Outbox;

class IumOutboxProcessCommand extends Command
{
    protected $signature = 'ium:outbox:process
        {--limit=50 : Maximum pending messages to process per batch}
        {--retry-failed : Reset failed messages to pending before processing}';

    protected $description = 'Process pending Obelawium (IUM) transactional outbox messages';

    public function handle(Outbox $outbox): int
    {
        if ((bool) $this->option('retry-failed')) {
            $requeued = $outbox->retryFailed();
            $this->info("Re-queued {$requeued} failed outbox message(s).");
        }

        $limit = max(1, (int) $this->option('limit'));
        $result = $outbox->processPending($limit);

        $this->info(
            "Outbox batch complete: processed={$result->processed}, dispatched={$result->dispatched}, retried={$result->retried}, failed={$result->failed}."
        );

        return self::SUCCESS;
    }
}
