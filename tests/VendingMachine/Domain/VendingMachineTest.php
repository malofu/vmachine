<?php

declare(strict_types=1);

use VendingMachine\Domain\CannotMakeChangeException;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\InsufficientMoneyException;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\OutOfStockException;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\VendingMachine;

/**
 * @param list<array{Coin, int}> $coins denomination and count to load
 */
function machineWith(int $waterStock, array $coins): VendingMachine
{
    $bank = CoinBank::empty();
    foreach ($coins as [$coin, $count]) {
        $bank = $bank->withCoins($coin, $count);
    }

    return VendingMachine::stocked(Inventory::empty()->withStock(Product::Water, $waterStock), $bank);
}

it('starts with a zero balance', function () {
    expect(VendingMachine::new()->insertedBalance())->toBe(0);
});

it('increases the balance when a coin is inserted', function () {
    $machine = VendingMachine::new();
    $machine->insertCoin(Coin::TwentyFiveCents);

    expect($machine->insertedBalance())->toBe(25);
});

it('accumulates the balance across several inserts', function () {
    $machine = VendingMachine::new();
    $machine->insertCoin(Coin::OneEuro);
    $machine->insertCoin(Coin::TwentyFiveCents);
    $machine->insertCoin(Coin::TwentyFiveCents);

    expect($machine->insertedBalance())->toBe(150);
});

it('returns all inserted coins and resets the balance to zero', function () {
    $machine = VendingMachine::new();
    $machine->insertCoin(Coin::TenCents);
    $machine->insertCoin(Coin::TenCents);

    $returned = $machine->returnCoins();

    expect($returned->coins())->toBe([Coin::TenCents, Coin::TenCents])
        ->and($machine->insertedBalance())->toBe(0);
});

it('returns nothing when no coins were inserted', function () {
    $machine = VendingMachine::new();

    expect($machine->returnCoins()->coins())->toBe([])
        ->and($machine->insertedBalance())->toBe(0);
});

it('sets an absolute item count when serviced', function () {
    $machine = machineWith(waterStock: 0, coins: []);

    $machine->setStock(Product::Water, 5);

    expect($machine->stockOf(Product::Water))->toBe(5)
        ->and($machine->isAvailable(Product::Water))->toBeTrue();
});

it('sets an absolute coin count for change when serviced', function () {
    $machine = machineWith(waterStock: 0, coins: []);

    $machine->setChange(Coin::TwentyFiveCents, 8);

    expect($machine->coinStockOf(Coin::TwentyFiveCents))->toBe(8)
        ->and($machine->changeAvailable())->toBe(200);
});

it('reports a product as available while it has stock and sold out once empty', function () {
    $machine = machineWith(waterStock: 1, coins: []);
    $machine->insertCoin(Coin::TwentyFiveCents);
    $machine->insertCoin(Coin::TwentyFiveCents);
    $machine->insertCoin(Coin::FiveCents);
    $machine->insertCoin(Coin::TenCents);

    expect($machine->isAvailable(Product::Water))->toBeTrue();

    $machine->buy(Product::Water);

    expect($machine->isAvailable(Product::Water))->toBeFalse();
});

it('sells a product, dispenses it with change and clears the balance', function () {
    $machine = machineWith(waterStock: 1, coins: [[Coin::TwentyFiveCents, 1], [Coin::TenCents, 1]]);
    $machine->insertCoin(Coin::OneEuro);

    $sale = $machine->buy(Product::Water);

    expect($sale->product())->toBe(Product::Water)
        ->and($sale->change())->toBe([Coin::TwentyFiveCents, Coin::TenCents])
        ->and($machine->insertedBalance())->toBe(0)
        ->and($machine->stockOf(Product::Water))->toBe(0);
});

it('sells for the exact amount and gives no change', function () {
    $machine = machineWith(waterStock: 1, coins: []);
    $machine->insertCoin(Coin::TwentyFiveCents);
    $machine->insertCoin(Coin::TwentyFiveCents);
    $machine->insertCoin(Coin::FiveCents);
    $machine->insertCoin(Coin::TenCents);

    $sale = $machine->buy(Product::Water);

    expect($sale->change())->toBe([])
        ->and($machine->insertedBalance())->toBe(0);
});

it('keeps the customer payment as change for future sales', function () {
    $machine = machineWith(waterStock: 1, coins: [[Coin::TwentyFiveCents, 1], [Coin::TenCents, 1]]);
    $machine->insertCoin(Coin::OneEuro);

    $machine->buy(Product::Water);

    // Started with 0.35, took in 1.00, gave back 0.35 in change: keeps 1.00.
    expect($machine->changeAvailable())->toBe(100);
});

it('refuses to sell an out-of-stock product and keeps the money inserted', function () {
    $machine = machineWith(waterStock: 0, coins: []);
    $machine->insertCoin(Coin::OneEuro);

    expect(fn () => $machine->buy(Product::Water))->toThrow(OutOfStockException::class)
        ->and($machine->insertedBalance())->toBe(100);
});

it('refuses to sell when the inserted money is insufficient and keeps it inserted', function () {
    $machine = machineWith(waterStock: 1, coins: []);
    $machine->insertCoin(Coin::TwentyFiveCents);

    expect(fn () => $machine->buy(Product::Water))->toThrow(InsufficientMoneyException::class)
        ->and($machine->insertedBalance())->toBe(25);
});

it('refuses to sell when it cannot compose exact change and keeps the money inserted', function () {
    $machine = machineWith(waterStock: 1, coins: []);
    $machine->insertCoin(Coin::OneEuro);

    expect(fn () => $machine->buy(Product::Water))->toThrow(CannotMakeChangeException::class)
        ->and($machine->insertedBalance())->toBe(100)
        ->and($machine->stockOf(Product::Water))->toBe(1);
});
