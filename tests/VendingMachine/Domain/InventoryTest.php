<?php

declare(strict_types=1);

use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\OutOfStockException;
use VendingMachine\Domain\Product;

it('holds no stock when empty', function () {
    $inventory = Inventory::empty();

    expect($inventory->countOf(Product::Water))->toBe(0)
        ->and($inventory->has(Product::Water))->toBeFalse();
});

it('stocks a product with a count', function () {
    $inventory = Inventory::empty()->withStock(Product::Juice, 3);

    expect($inventory->countOf(Product::Juice))->toBe(3)
        ->and($inventory->has(Product::Juice))->toBeTrue();
});

it('dispenses one unit and leaves the original untouched', function () {
    $stocked = Inventory::empty()->withStock(Product::Soda, 2);

    $afterDispense = $stocked->dispense(Product::Soda);

    expect($afterDispense->countOf(Product::Soda))->toBe(1)
        ->and($stocked->countOf(Product::Soda))->toBe(2);
});

it('refuses to dispense a product it does not stock', function () {
    expect(fn () => Inventory::empty()->dispense(Product::Water))
        ->toThrow(OutOfStockException::class);
});
