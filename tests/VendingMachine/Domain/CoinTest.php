<?php

declare(strict_types=1);

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\InvalidCoinException;

it('accepts the four valid denominations', function (int $cents) {
    expect(Coin::fromCents($cents)->cents())->toBe($cents);
})->with([
    '0.05' => [5],
    '0.10' => [10],
    '0.25' => [25],
    '1.00' => [100],
]);

it('rejects any other denomination', function (int $cents) {
    expect(fn () => Coin::fromCents($cents))->toThrow(InvalidCoinException::class);
})->with([
    '0.01' => [1],
    '0.03' => [3],
    '0.50' => [50],
    '0.75' => [75],
    '2.00' => [200],
]);
