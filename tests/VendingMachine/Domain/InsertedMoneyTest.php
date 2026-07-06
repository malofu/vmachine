<?php

declare(strict_types=1);

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\InsertedMoney;

it('starts empty with a zero total', function () {
    expect(InsertedMoney::none()->total())->toBe(0)
        ->and(InsertedMoney::none()->coins())->toBe([]);
});

it('accumulates the total as coins are added', function () {
    $money = InsertedMoney::none()
        ->add(Coin::TwentyFiveCents)
        ->add(Coin::OneEuro);

    expect($money->total())->toBe(125);
});

it('is immutable: add returns a new instance and leaves the original untouched', function () {
    $empty = InsertedMoney::none();
    $withCoin = $empty->add(Coin::FiveCents);

    expect($empty->total())->toBe(0)
        ->and($withCoin->total())->toBe(5)
        ->and($withCoin)->not->toBe($empty);
});

it('preserves the order coins were inserted', function () {
    $money = InsertedMoney::none()
        ->add(Coin::FiveCents)
        ->add(Coin::OneEuro)
        ->add(Coin::TenCents);

    expect($money->coins())->toBe([Coin::FiveCents, Coin::OneEuro, Coin::TenCents]);
});

it('tallies its coins per denomination for a repository to snapshot', function () {
    $money = InsertedMoney::none()
        ->add(Coin::TenCents)
        ->add(Coin::OneEuro)
        ->add(Coin::TenCents);

    expect($money->counts())->toBe([10 => 2, 100 => 1]);
});

it('rebuilds from a per-denomination tally, preserving the total', function () {
    $money = InsertedMoney::fromCounts([25 => 2, 5 => 1]);

    expect($money->total())->toBe(55)
        ->and($money->counts())->toBe([25 => 2, 5 => 1]);
});

it('round-trips counts() and fromCounts() without changing the total', function () {
    $original = InsertedMoney::none()
        ->add(Coin::OneEuro)
        ->add(Coin::FiveCents)
        ->add(Coin::OneEuro);

    $rebuilt = InsertedMoney::fromCounts($original->counts());

    expect($rebuilt->total())->toBe($original->total());
});

it('is empty when rebuilt from no counts', function () {
    expect(InsertedMoney::fromCounts([])->total())->toBe(0)
        ->and(InsertedMoney::fromCounts([])->coins())->toBe([]);
});
