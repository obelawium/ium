<?php

declare(strict_types=1);

namespace Obelaw\Ium\Support;

use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\Models\SequenceCounter;

/**
 * Concurrency-safe, tenant-isolated atomic sequence generator.
 *
 * Persists counters in `ium_sequences` on the active TenantContext connection,
 * using row-level `lockForUpdate()` inside a transaction so concurrent requests
 * never produce duplicate document numbers and each tenant maintains independent series.
 */
final class Sequence
{
    /**
     * Increment and return the next raw integer value for a named sequence.
     */
    public function nextValue(string $key): int
    {
        $normalized = trim($key);

        if ($normalized === '') {
            throw new InvalidArgumentException('Sequence key cannot be empty.');
        }

        return Ium::transaction(function () use ($normalized): int {
            $counter = SequenceCounter::query()
                ->where('name', $normalized)
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                try {
                    $counter = SequenceCounter::query()->create([
                        'name' => $normalized,
                        'current_value' => 1,
                    ]);

                    return (int) $counter->current_value;
                } catch (QueryException) {
                    // Concurrent insert won the race — lock and increment the existing row.
                    $counter = SequenceCounter::query()
                        ->where('name', $normalized)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            $next = (int) $counter->current_value + 1;
            $counter->update(['current_value' => $next]);

            return $next;
        });
    }

    /**
     * Increment and format the next document code (e.g. `JE-2026-000001`).
     */
    public function next(
        string $key,
        ?string $prefix = null,
        int $padding = 6,
        string $separator = '-',
    ): string {
        $value = $this->nextValue($key);
        $padded = $padding > 0
            ? str_pad((string) $value, $padding, '0', STR_PAD_LEFT)
            : (string) $value;

        if ($prefix === null || $prefix === '') {
            return $padded;
        }

        return $prefix . $separator . $padded;
    }

    /**
     * Read the current sequence value without incrementing it.
     */
    public function current(string $key): int
    {
        $counter = SequenceCounter::query()->where('name', trim($key))->first();

        return $counter !== null ? (int) $counter->current_value : 0;
    }

    /**
     * Reset a sequence counter to a specific value (default 0).
     */
    public function reset(string $key, int $value = 0): void
    {
        $normalized = trim($key);

        if ($value < 0) {
            throw new InvalidArgumentException('Sequence value cannot be negative.');
        }

        Ium::transaction(static function () use ($normalized, $value): void {
            SequenceCounter::query()->updateOrCreate(
                ['name' => $normalized],
                ['current_value' => $value],
            );
        });
    }
}
