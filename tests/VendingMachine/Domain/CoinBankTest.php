<?php

declare(strict_types=1);

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;

it('holds nothing when empty', function () {
    expect(CoinBank::empty()->total())->toBe(0);
});

it('reports how many coins of a denomination it holds', function () {
    $bank = CoinBank::empty()->withCoins(Coin::TwentyFiveCents, 4);

    expect($bank->countOf(Coin::TwentyFiveCents))->toBe(4)
        ->and($bank->countOf(Coin::OneEuro))->toBe(0);
});

it('totals the coins it holds', function () {
    $bank = CoinBank::empty()
        ->withCoins(Coin::OneEuro, 2)
        ->withCoins(Coin::TwentyFiveCents, 3);

    expect($bank->total())->toBe(275);
});

it('adds deposited coins to what it holds', function () {
    $bank = CoinBank::empty()
        ->withCoins(Coin::TenCents, 1)
        ->deposit([Coin::TenCents, Coin::FiveCents]);

    expect($bank->total())->toBe(25);
});

it('withdraws nothing for a zero amount and always succeeds', function () {
    $bank = CoinBank::empty()->withCoins(Coin::TenCents, 1);

    $withdrawal = $bank->withdraw(0);
    assert($withdrawal !== null);
    [$remaining, $change] = $withdrawal;

    expect($change)->toBe([])
        ->and($remaining->total())->toBe(10);
});

it('withdraws exact change and reduces the bank', function () {
    $bank = CoinBank::empty()
        ->withCoins(Coin::TwentyFiveCents, 1)
        ->withCoins(Coin::TenCents, 1);

    $withdrawal = $bank->withdraw(35);
    assert($withdrawal !== null);
    [$remaining, $change] = $withdrawal;

    expect($change)->toBe([Coin::TwentyFiveCents, Coin::TenCents])
        ->and($remaining->total())->toBe(0);
});

it('composes change from smaller coins when the greedy choice would fail', function () {
    // 0.30 out of {0.25 x1, 0.10 x3}: taking the 0.25 leaves 0.05, which is not
    // available, so the only valid change is three 0.10 coins.
    $bank = CoinBank::empty()
        ->withCoins(Coin::TwentyFiveCents, 1)
        ->withCoins(Coin::TenCents, 3);

    $withdrawal = $bank->withdraw(30);
    assert($withdrawal !== null);
    [$remaining, $change] = $withdrawal;

    expect($change)->toBe([Coin::TenCents, Coin::TenCents, Coin::TenCents])
        ->and($remaining->total())->toBe(25);
});

it('returns null when the exact amount cannot be composed', function () {
    $bank = CoinBank::empty()->withCoins(Coin::TwentyFiveCents, 1);

    expect($bank->withdraw(30))->toBeNull();
});

it('returns null when it does not hold enough coins', function () {
    $bank = CoinBank::empty()->withCoins(Coin::TenCents, 1);

    expect($bank->withdraw(30))->toBeNull();
});
