<?php

declare(strict_types=1);

use VendingMachine\Application\BuyProduct\BuyProductCommand;
use VendingMachine\Application\BuyProduct\BuyProductHandler;
use VendingMachine\Application\InsertCoin\InsertCoinCommand;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Domain\CannotMakeChangeException;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\InsufficientMoneyException;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\OutOfStockException;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\UnknownProductException;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

/**
 * @param list<array{Coin, int}> $coins
 */
function repositoryFor(int $waterStock, array $coins): InMemoryVendingMachineRepository
{
    $bank = CoinBank::empty();
    foreach ($coins as [$coin, $count]) {
        $bank = $bank->withCoins($coin, $count);
    }

    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked(
        Inventory::empty()->withStock(Product::Water, $waterStock),
        $bank,
    ));

    return $repository;
}

it('buys a product and reports the selector, change and cleared balance', function () {
    $repository = repositoryFor(waterStock: 1, coins: [[Coin::TwentyFiveCents, 1], [Coin::TenCents, 1]]);
    (new InsertCoinHandler($repository))(new InsertCoinCommand(100));

    $response = (new BuyProductHandler($repository))(new BuyProductCommand('water'));

    expect($response->productSelector)->toBe('WATER')
        ->and($response->changeInCents)->toBe([25, 10])
        ->and($response->balanceInCents)->toBe(0)
        ->and($repository->get()->stockOf(Product::Water))->toBe(0);
});

it('rejects an unknown product and leaves the balance untouched', function () {
    $repository = repositoryFor(waterStock: 1, coins: []);
    (new InsertCoinHandler($repository))(new InsertCoinCommand(100));
    $handler = new BuyProductHandler($repository);

    expect(fn () => $handler(new BuyProductCommand('cola')))->toThrow(UnknownProductException::class);
    expect($repository->get()->insertedBalance())->toBe(100);
});

it('fails to buy an out-of-stock product and keeps the balance', function () {
    $repository = repositoryFor(waterStock: 0, coins: []);
    (new InsertCoinHandler($repository))(new InsertCoinCommand(100));
    $handler = new BuyProductHandler($repository);

    expect(fn () => $handler(new BuyProductCommand('water')))->toThrow(OutOfStockException::class);
    expect($repository->get()->insertedBalance())->toBe(100);
});

it('fails to buy with insufficient money and keeps the balance', function () {
    $repository = repositoryFor(waterStock: 1, coins: []);
    (new InsertCoinHandler($repository))(new InsertCoinCommand(25));
    $handler = new BuyProductHandler($repository);

    expect(fn () => $handler(new BuyProductCommand('water')))->toThrow(InsufficientMoneyException::class);
    expect($repository->get()->insertedBalance())->toBe(25);
});

it('fails to buy when exact change cannot be composed and keeps the balance', function () {
    $repository = repositoryFor(waterStock: 1, coins: []);
    (new InsertCoinHandler($repository))(new InsertCoinCommand(100));
    $handler = new BuyProductHandler($repository);

    expect(fn () => $handler(new BuyProductCommand('water')))->toThrow(CannotMakeChangeException::class);
    expect($repository->get()->insertedBalance())->toBe(100);
});
