<?php

declare(strict_types=1);

namespace Obelaw\Ium\Tests\Unit;

use InvalidArgumentException;
use Obelaw\Ium\Support\Money;

it('constructs exact minor units for 0, 2, and 3 decimal ISO currencies without float drift', function () {
    $usd = Money::fromMajor('100.50', 'USD');
    $kwd = Money::fromMajor('10.125', 'KWD');
    $jpy = Money::fromMajor('500', 'JPY');
    $floatDrift = Money::fromMajor(19.99, 'USD');

    expect($usd->amount)->toBe(10050)
        ->and($usd->decimals)->toBe(2)
        ->and($usd->toMajor())->toBe('100.50')
        ->and($usd->format())->toBe('100.50 USD')
        ->and($kwd->amount)->toBe(10125)
        ->and($kwd->decimals)->toBe(3)
        ->and($kwd->toMajor())->toBe('10.125')
        ->and($kwd->format())->toBe('10.125 KWD')
        ->and($jpy->amount)->toBe(500)
        ->and($jpy->decimals)->toBe(0)
        ->and($jpy->toMajor())->toBe('500')
        ->and($floatDrift->amount)->toBe(1999);
});

it('allocates amounts with zero rounding drift (solves debit 4 vs credit 3 accounting bug)', function () {
    // Splitting 100 minor units across 3 equal lines: naive round() gives 33+33+33 = 99 (imbalanced!)
    $shares = Money::minor(100, 'USD')->split(3);

    expect(array_map(static fn (Money $m): int => $m->amount, $shares))->toBe([34, 33, 33])
        ->and(Money::sum(...$shares)->amount)->toBe(100);

    // Weighted allocation (e.g. multi-line invoice tax/discount split)
    $discount = Money::fromMajor('10.00', 'SAR');
    $allocated = $discount->allocate([33.33, 33.33, 33.34]);

    expect(Money::sum(...$allocated)->equals($discount))->toBeTrue();

    // Negative allocation preserves symmetry and exact sum
    $negative = Money::minor(-100, 'USD')->split(3);
    expect(array_map(static fn (Money $m): int => $m->amount, $negative))->toBe([-34, -33, -33])
        ->and(Money::sum(...$negative)->amount)->toBe(-100);
});

it('rejects arithmetic across mismatched currencies', function () {
    $usd = Money::minor(1000, 'USD');
    $sar = Money::minor(1000, 'SAR');

    expect(fn () => $usd->add($sar))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $usd->subtract($sar))->toThrow(InvalidArgumentException::class);
});
