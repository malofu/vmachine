<?php

declare(strict_types=1);

use VendingMachine\Domain\InvalidProductException;
use VendingMachine\Domain\Product;

it('holds a selector and a price in cents', function () {
    $product = Product::new('WATER', 65);

    expect($product->selector())->toBe('WATER')
        ->and($product->price())->toBe(65);
});

it('normalizes the selector to trimmed uppercase', function () {
    $product = Product::new('  cola  ', 125);

    expect($product->selector())->toBe('COLA');
});

it('rejects an empty selector', function () {
    expect(fn () => Product::new('   ', 65))->toThrow(InvalidProductException::class);
});

it('rejects a non-positive price', function (int $priceInCents) {
    expect(fn () => Product::new('WATER', $priceInCents))->toThrow(InvalidProductException::class);
})->with([
    'zero' => [0],
    'negative' => [-10],
]);
