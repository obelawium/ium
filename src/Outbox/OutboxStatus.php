<?php

declare(strict_types=1);

namespace Obelaw\Ium\Outbox;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Dispatched = 'dispatched';
    case Failed = 'failed';
}
