<?php

declare(strict_types=1);

use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\OutOfStockException;

it('holds no stock when empty', function () {
    $inventory = Inventory::empty();

    expect($inventory->countOf('WATER'))->toBe(0)
        ->and($inventory->has('WATER'))->toBeFalse();
});

it('stocks a product with a count', function () {
    $inventory = Inventory::empty()->withStock('JUICE', 3);

    expect($inventory->countOf('JUICE'))->toBe(3)
        ->and($inventory->has('JUICE'))->toBeTrue();
});

it('dispenses one unit and leaves the original untouched', function () {
    $stocked = Inventory::empty()->withStock('SODA', 2);

    $afterDispense = $stocked->dispense('SODA');

    expect($afterDispense->countOf('SODA'))->toBe(1)
        ->and($stocked->countOf('SODA'))->toBe(2);
});

it('refuses to dispense a product it does not stock', function () {
    expect(fn () => Inventory::empty()->dispense('WATER'))
        ->toThrow(OutOfStockException::class);
});

it('drops a slot entirely', function () {
    $inventory = Inventory::empty()->withStock('WATER', 5)->without('WATER');

    expect($inventory->countOf('WATER'))->toBe(0);
});
