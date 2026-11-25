<?php

declare(strict_types=1);

namespace Obelaw\Ium\Outbox;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Obelaw\Ium\Facades\Ium;
use Throwable;

/**
 * Transactional Outbox for reliable, idempotent cross-domain integration with retry backoff.
 *
 * Messages are persisted atomically on the active TenantContext connection inside
 * the producer's domain transaction and dispatched reliably to registered topic handlers.
 */
final class Outbox
{
    /**
     * Registered handlers keyed by topic (or '*' wildcard).
     *
     * @var array<string, list<callable(OutboxMessage): void>>
     */
    private array $handlers = [];

    /**
     * Publish an event into the transactional outbox.
     *
     * When `$idempotencyKey` is provided and already exists, returns the existing
     * message without creating a duplicate.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    public function publish(
        string $topic,
        array $payload,
        ?string $idempotencyKey = null,
        array $headers = [],
        int $maxAttempts = 5,
    ): OutboxMessage {
        $normalizedTopic = trim($topic);

        if ($normalizedTopic === '') {
            throw new InvalidArgumentException('Outbox topic cannot be empty.');
        }

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Outbox maxAttempts must be at least 1.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = OutboxMessage::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return OutboxMessage::query()->create([
            'idempotency_key' => $idempotencyKey,
            'topic' => $normalizedTopic,
            'payload' => $payload,
            'headers' => $headers === [] ? null : $headers,
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => Carbon::now(),
        ]);
    }

    /**
     * Register a subscriber handler for a specific topic (or '*' for all topics).
     *
     * @param callable(OutboxMessage): void $handler
     */
    public function subscribe(string $topic, callable $handler): self
    {
        $this->handlers[$topic][] = $handler;

        return $this;
    }

    /**
     * Process due pending outbox messages on the active connection with retry backoff.
     */
    public function processPending(int $limit = 50, int $baseBackoffSeconds = 30): OutboxDispatchResult
    {
        $now = Carbon::now();

        $messages = Ium::transaction(static function () use ($now, $limit) {
            $batch = OutboxMessage::query()
                ->where('status', OutboxStatus::Pending->value)
                ->where(static function ($query) use ($now): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', $now);
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($batch as $message) {
                $message->update(['status' => OutboxStatus::Processing]);
            }

            return $batch;
        });

        $dispatched = 0;
        $retried = 0;
        $failed = 0;

        foreach ($messages as $message) {
            $handlers = array_merge(
                $this->handlers[$message->topic] ?? [],
                $this->handlers['*'] ?? [],
            );

            try {
                foreach ($handlers as $handler) {
                    $handler($message);
                }

                $message->update([
                    'status' => OutboxStatus::Dispatched,
                    'attempts' => $message->attempts + 1,
                    'dispatched_at' => Carbon::now(),
                    'last_error' => null,
                ]);

                $dispatched++;
            } catch (Throwable $e) {
                $attempts = $message->attempts + 1;
                $exhausted = $attempts >= $message->max_attempts;

                $delaySeconds = $baseBackoffSeconds * (2 ** max(0, $attempts - 1));

                $message->update([
                    'status' => $exhausted ? OutboxStatus::Failed : OutboxStatus::Pending,
                    'attempts' => $attempts,
                    'available_at' => $exhausted ? null : Carbon::now()->addSeconds($delaySeconds),
                    'last_error' => $e->getMessage(),
                ]);

                if ($exhausted) {
                    $failed++;
                } else {
                    $retried++;
                }
            }
        }

        return new OutboxDispatchResult(
            processed: $messages->count(),
            dispatched: $dispatched,
            retried: $retried,
            failed: $failed,
        );
    }

    /**
     * Re-queue failed outbox messages back to pending for replay.
     */
    public function retryFailed(?string $topic = null): int
    {
        $query = OutboxMessage::query()->where('status', OutboxStatus::Failed->value);

        if ($topic !== null && $topic !== '') {
            $query->where('topic', $topic);
        }

        return $query->update([
            'status' => OutboxStatus::Pending->value,
            'attempts' => 0,
            'available_at' => Carbon::now(),
            'last_error' => null,
        ]);
    }

    /**
     * Clear registered in-memory handlers (useful for test isolation).
     */
    public function resetHandlers(): void
    {
        $this->handlers = [];
    }
}
