<?php

declare(strict_types=1);

namespace Obelaw\Ium\Outbox;

/**
 * Immutable summary returned by Outbox::processPending().
 */
final readonly class OutboxDispatchResult
{
    public function __construct(
        public int $processed,
        public int $dispatched,
        public int $retried,
        public int $failed,
    ) {}
}
