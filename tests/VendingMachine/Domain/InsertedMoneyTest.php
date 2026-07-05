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
