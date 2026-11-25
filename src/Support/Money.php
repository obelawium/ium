<?php

declare(strict_types=1);

namespace Obelaw\Ium\Support;

use InvalidArgumentException;

/**
 * Immutable minor-unit monetary value object with ISO 4217 decimal precision
 * and penny-exact Largest-Remainder allocation (zero rounding drift).
 */
final readonly class Money
{
    /**
     * ISO 4217 currencies with non-standard (0 or 3) minor unit decimals.
     *
     * @var array<string, int>
     */
    private const CURRENCY_DECIMALS = [
        // 0-decimal currencies
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0,
        'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0,
        'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // 3-decimal currencies (critical for MENA / Gulf ERP deployments)
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public string $currency;

    public int $decimals;

    public function __construct(
        public int $amount,
        string $currency,
        ?int $decimals = null,
    ) {
        $normalized = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $normalized)) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code [{$currency}].");
        }

        $resolvedDecimals = $decimals ?? self::decimalsFor($normalized);

        if ($resolvedDecimals < 0 || $resolvedDecimals > 6) {
            throw new InvalidArgumentException("Currency decimals must be between 0 and 6, got {$resolvedDecimals}.");
        }

        $this->currency = $normalized;
        $this->decimals = $resolvedDecimals;
    }

    public static function minor(int $amount, string $currency, ?int $decimals = null): self
    {
        return new self($amount, $currency, $decimals);
    }

    public static function zero(string $currency, ?int $decimals = null): self
    {
        return new self(0, $currency, $decimals);
    }

    /**
     * Construct Money from a major-unit decimal value using exact string arithmetic
     * to avoid IEEE-754 floating-point drift.
     */
    public static function fromMajor(int|float|string $amount, string $currency, ?int $decimals = null): self
    {
        $normalizedCurrency = strtoupper(trim($currency));
        $resolvedDecimals = $decimals ?? self::decimalsFor($normalizedCurrency);

        $raw = is_string($amount)
            ? trim($amount)
            : (is_int($amount) ? (string) $amount : sprintf('%.' . ($resolvedDecimals + 4) . 'F', $amount));

        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $raw, $matches)) {
            throw new InvalidArgumentException("Invalid major monetary amount [{$raw}].");
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = $matches[2];
        $fraction = $matches[3] ?? '';

        if ($resolvedDecimals === 0) {
            $rounded = (int) $whole;

            if ($fraction !== '' && (int) $fraction[0] >= 5) {
                $rounded++;
            }

            return new self($sign * $rounded, $normalizedCurrency, 0);
        }

        $padded = str_pad($fraction, $resolvedDecimals + 1, '0', STR_PAD_RIGHT);
        $kept = substr($padded, 0, $resolvedDecimals);
        $nextDigit = (int) $padded[$resolvedDecimals];

        $minor = ((int) $whole * (10 ** $resolvedDecimals)) + (int) $kept;

        if ($nextDigit >= 5) {
            $minor++;
        }

        return new self($sign * $minor, $normalizedCurrency, $resolvedDecimals);
    }

    public static function decimalsFor(string $currency): int
    {
        return self::CURRENCY_DECIMALS[strtoupper(trim($currency))] ?? 2;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency, $this->decimals);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency, $this->decimals);
    }

    public function multiply(int|float|string $factor): self
    {
        if (! is_numeric($factor)) {
            throw new InvalidArgumentException("Multiplication factor must be numeric.");
        }

        $result = (int) round($this->amount * (float) $factor, 0, PHP_ROUND_HALF_UP);

        return new self($result, $this->currency, $this->decimals);
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency, $this->decimals);
    }

    public function abs(): self
    {
        return new self(abs($this->amount), $this->currency, $this->decimals);
    }

    /**
     * Split this amount into N equal shares with zero rounding drift.
     *
     * @return list<self>
     */
    public function split(int $parts): array
    {
        if ($parts <= 0) {
            throw new InvalidArgumentException('Split parts must be greater than zero.');
        }

        return $this->allocate(array_fill(0, $parts, 1));
    }

    /**
     * Allocate this amount proportionally across ratios using the Largest Remainder Method.
     *
     * Guarantees `sum(allocated) === $this->amount` with zero penny drift.
     *
     * @param array<int|string, int|float> $ratios Non-negative weights (keys preserved in order as list).
     *
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Allocation ratios cannot be empty.');
        }

        $weights = [];
        $totalWeight = 0.0;

        foreach ($ratios as $ratio) {
            $weight = (float) $ratio;

            if ($weight < 0.0) {
                throw new InvalidArgumentException('Allocation ratios cannot be negative.');
            }

            $weights[] = $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0.0) {
            throw new InvalidArgumentException('Sum of allocation ratios must be greater than zero.');
        }

        $sign = $this->amount < 0 ? -1 : 1;
        $absoluteTotal = abs($this->amount);

        $shares = [];
        $remainders = [];
        $allocatedSum = 0;

        foreach ($weights as $index => $weight) {
            $exact = ($absoluteTotal * $weight) / $totalWeight;
            $floor = (int) floor($exact);
            $shares[$index] = $floor;
            $remainders[$index] = $exact - $floor;
            $allocatedSum += $floor;
        }

        $leftover = $absoluteTotal - $allocatedSum;

        if ($leftover > 0) {
            $indices = array_keys($remainders);
            usort($indices, static function (int $a, int $b) use ($remainders): int {
                $cmp = $remainders[$b] <=> $remainders[$a];

                return $cmp !== 0 ? $cmp : ($a <=> $b);
            });

            for ($i = 0; $i < $leftover; $i++) {
                $shares[$indices[$i]]++;
            }
        }

        $results = [];

        foreach ($shares as $share) {
            $results[] = new self($sign * $share, $this->currency, $this->decimals);
        }

        return $results;
    }

    /**
     * Sum one or more Money instances of the same currency.
     */
    public static function sum(self ...$items): self
    {
        if ($items === []) {
            throw new InvalidArgumentException('Money::sum() requires at least one Money instance.');
        }

        $total = array_shift($items);

        foreach ($items as $item) {
            $total = $total->add($item);
        }

        return $total;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->decimals === $other->decimals
            && $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /**
     * Exact major-unit decimal string (e.g. '100.50', '10.125', '500').
     */
    public function toMajor(): string
    {
        if ($this->decimals === 0) {
            return (string) $this->amount;
        }

        $sign = $this->amount < 0 ? '-' : '';
        $abs = abs($this->amount);
        $divisor = 10 ** $this->decimals;
        $whole = intdiv($abs, $divisor);
        $fraction = str_pad((string) ($abs % $divisor), $this->decimals, '0', STR_PAD_LEFT);

        return "{$sign}{$whole}.{$fraction}";
    }

    public function toFloat(): float
    {
        return (float) $this->toMajor();
    }

    public function format(bool $withCurrency = true): string
    {
        $major = $this->toMajor();
        $sign = str_starts_with($major, '-') ? '-' : '';
        $unsigned = ltrim($major, '-');

        if ($this->decimals === 0) {
            $formatted = $sign . number_format((float) $unsigned, 0, '.', ',');
        } else {
            [$whole, $fraction] = explode('.', $unsigned);
            $formatted = $sign . number_format((float) $whole, 0, '.', ',') . '.' . $fraction;
        }

        return $withCurrency ? "{$formatted} {$this->currency}" : $formatted;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency || $this->decimals !== $other->decimals) {
            throw new InvalidArgumentException(
                "Currency mismatch: cannot combine [{$this->currency}:{$this->decimals}] with [{$other->currency}:{$other->decimals}]."
            );
        }
    }
}
