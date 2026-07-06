<?php

declare(strict_types=1);

use VendingMachine\Domain\CannotMakeChangeException;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\InsertedMoney;
use VendingMachine\Domain\InsufficientMoneyException;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\OutOfStockException;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\ProductInStockException;
use VendingMachine\Domain\UnknownProductException;
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

    return VendingMachine::stocked(
        ProductCatalogue::empty()->withProduct(Product::new('WATER', 65)),
        Inventory::empty()->withStock('WATER', $waterStock),
        $bank,
    );
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

it('defines a product in the catalogue when serviced', function () {
    $machine = machineWith(waterStock: 0, coins: []);

    $machine->setProduct(Product::new('COLA', 125));

    expect($machine->catalogue()->get('COLA')->price())->toBe(125);
});

it('sets an absolute item count when serviced', function () {
    $machine = machineWith(waterStock: 0, coins: []);

    $machine->setStock('WATER', 5);

    expect($machine->stockOf('WATER'))->toBe(5)
        ->and($machine->isAvailable('WATER'))->toBeTrue();
});

it('refuses to stock a product that is not in the catalogue', function () {
    $machine = machineWith(waterStock: 0, coins: []);

    expect(fn () => $machine->setStock('COLA', 5))->toThrow(UnknownProductException::class);
});

it('removes a product once its slot is empty', function () {
    $machine = machineWith(waterStock: 0, coins: []);

    $machine->removeProduct('WATER');

    expect($machine->catalogue()->has('WATER'))->toBeFalse();
});

it('refuses to remove a product that still has stock', function () {
    $machine = machineWith(waterStock: 3, coins: []);

    expect(fn () => $machine->removeProduct('WATER'))->toThrow(ProductInStockException::class)
        ->and($machine->catalogue()->has('WATER'))->toBeTrue();
});

it('sets an absolute coin count for change when serviced', function () {
    $machine = machineWith(waterStock: 0, coins: []);

    $machine->setChange(Coin::TwentyFiveCents, 8);

    expect($machine->coinStockOf(Coin::TwentyFiveCents))->toBe(8)
        ->and($machine->changeAvailable())->toBe(200);
});

it('rejects an unknown selector on purchase', function () {
    $machine = machineWith(waterStock: 1, coins: []);
    $machine->insertCoin(Coin::OneEuro);

    expect(fn () => $machine->buy('COLA'))->toThrow(UnknownProductException::class);
});

it('reports a product as available while it has stock and sold out once empty', function () {
    $machine = machineWith(waterStock: 1, coins: []);
    $machine->insertCoin(Coin::TwentyFiveCents);
    $machine->insertCoin(Coin::TwentyFiveCents);
    $machine->insertCoin(Coin::FiveCents);
    $machine->insertCoin(Coin::TenCents);

    expect($machine->isAvailable('WATER'))->toBeTrue();

    $machine->buy('WATER');

    expect($machine->isAvailable('WATER'))->toBeFalse();
});

it('sells a product, dispenses it with change and clears the balance', function () {
    $machine = machineWith(waterStock: 1, coins: [[Coin::TwentyFiveCents, 1], [Coin::TenCents, 1]]);
    $machine->insertCoin(Coin::OneEuro);

    $sale = $machine->buy('WATER');

    expect($sale->product()->selector())->toBe('WATER')
        ->and($sale->change())->toBe([Coin::TwentyFiveCents, Coin::TenCents])
        ->and($machine->insertedBalance())->toBe(0)
        ->and($machine->stockOf('WATER'))->toBe(0);
});

it('sells for the exact amount and gives no change', function () {
    $machine = machineWith(waterStock: 1, coins: []);
    $machine->insertCoin(Coin::TwentyFiveCents);
    $machine->insertCoin(Coin::TwentyFiveCents);
    $machine->insertCoin(Coin::FiveCents);
    $machine->insertCoin(Coin::TenCents);

    $sale = $machine->buy('WATER');

    expect($sale->change())->toBe([])
        ->and($machine->insertedBalance())->toBe(0);
});

it('keeps the customer payment as change for future sales', function () {
    $machine = machineWith(waterStock: 1, coins: [[Coin::TwentyFiveCents, 1], [Coin::TenCents, 1]]);
    $machine->insertCoin(Coin::OneEuro);

    $machine->buy('WATER');

    // Started with 0.35, took in 1.00, gave back 0.35 in change: keeps 1.00.
    expect($machine->changeAvailable())->toBe(100);
});

it('refuses to sell an out-of-stock product and keeps the money inserted', function () {
    $machine = machineWith(waterStock: 0, coins: []);
    $machine->insertCoin(Coin::OneEuro);

    expect(fn () => $machine->buy('WATER'))->toThrow(OutOfStockException::class)
        ->and($machine->insertedBalance())->toBe(100);
});

it('refuses to sell when the inserted money is insufficient and keeps it inserted', function () {
    $machine = machineWith(waterStock: 1, coins: []);
    $machine->insertCoin(Coin::TwentyFiveCents);

    expect(fn () => $machine->buy('WATER'))->toThrow(InsufficientMoneyException::class)
        ->and($machine->insertedBalance())->toBe(25);
});

it('refuses to sell when it cannot compose exact change and keeps the money inserted', function () {
    $machine = machineWith(waterStock: 1, coins: []);
    $machine->insertCoin(Coin::OneEuro);

    expect(fn () => $machine->buy('WATER'))->toThrow(CannotMakeChangeException::class)
        ->and($machine->insertedBalance())->toBe(100)
        ->and($machine->stockOf('WATER'))->toBe(1);
});

it('exposes its state through the persistence accessors', function () {
    $machine = machineWith(waterStock: 4, coins: [[Coin::OneEuro, 2]]);
    $machine->insertCoin(Coin::TwentyFiveCents);

    expect($machine->inventory()->counts())->toBe(['WATER' => 4])
        ->and($machine->coinBank()->counts())->toBe([100 => 2])
        ->and($machine->insertedMoney()->counts())->toBe([25 => 1]);
});

it('restores a machine from persisted state, resuming the inserted balance', function () {
    $machine = VendingMachine::restore(
        ProductCatalogue::empty()->withProduct(Product::new('SODA', 150)),
        Inventory::empty()->withStock('SODA', 2),
        CoinBank::empty()->withCoins(Coin::TenCents, 5),
        InsertedMoney::fromCounts([100 => 1, 25 => 1]),
    );

    expect($machine->insertedBalance())->toBe(125)
        ->and($machine->stockOf('SODA'))->toBe(2)
        ->and($machine->catalogue()->get('SODA')->price())->toBe(150)
        ->and($machine->coinStockOf(Coin::TenCents))->toBe(5);
});
