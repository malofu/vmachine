<?php

declare(strict_types=1);

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\VendingMachine;

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
