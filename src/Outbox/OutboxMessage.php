<?php

declare(strict_types=1);

namespace Obelaw\Ium\Outbox;

use Illuminate\Support\Carbon;
use Obelaw\Ium\Bases\ModelBase;

/**
 * @property int $id
 * @property string|null $idempotency_key
 * @property string $topic
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $headers
 * @property OutboxStatus $status
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon|null $available_at
 * @property Carbon|null $dispatched_at
 * @property string|null $last_error
 */
final class OutboxMessage extends ModelBase
{
    protected $table = 'outbox_messages';

    protected $fillable = [
        'idempotency_key',
        'topic',
        'payload',
        'headers',
        'status',
        'attempts',
        'max_attempts',
        'available_at',
        'dispatched_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'status' => OutboxStatus::class,
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'available_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];
}
