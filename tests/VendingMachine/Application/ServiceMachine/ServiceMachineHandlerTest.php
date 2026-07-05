<?php

declare(strict_types=1);

use VendingMachine\Application\BuyProduct\BuyProductCommand;
use VendingMachine\Application\BuyProduct\BuyProductHandler;
use VendingMachine\Application\InsertCoin\InsertCoinCommand;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Application\ServiceMachine\ServiceMachineCommand;
use VendingMachine\Application\ServiceMachine\ServiceMachineHandler;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\InvalidCoinException;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\UnknownProductException;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

function emptyMachineRepository(): InMemoryVendingMachineRepository
{
    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked(Inventory::empty(), CoinBank::empty()));

    return $repository;
}

it('sets item and coin counts and reports the resulting state', function () {
    $repository = emptyMachineRepository();

    $report = (new ServiceMachineHandler($repository))(new ServiceMachineCommand(
        productCounts: ['WATER' => 5, 'SODA' => 3],
        coinCounts: [25 => 8, 100 => 2],
    ));

    expect($repository->get()->stockOf(Product::Water))->toBe(5)
        ->and($repository->get()->stockOf(Product::Soda))->toBe(3)
        ->and($repository->get()->coinStockOf(Coin::TwentyFiveCents))->toBe(8)
        ->and($report->changeTotalInCents)->toBe(400);

    [$water] = $report->products;
    expect($water->selector)->toBe('WATER')
        ->and($water->priceInCents)->toBe(65)
        ->and($water->count)->toBe(5);
});

it('returns the current report unchanged for an empty command', function () {
    $repository = emptyMachineRepository();
    $repository->get()->setStock(Product::Juice, 4);

    $report = (new ServiceMachineHandler($repository))(new ServiceMachineCommand());

    [, $juice] = $report->products;
    expect($juice->selector)->toBe('JUICE')
        ->and($juice->count)->toBe(4);
});

it('accepts case-insensitive product selectors', function () {
    $repository = emptyMachineRepository();

    (new ServiceMachineHandler($repository))(new ServiceMachineCommand(productCounts: ['water' => 2]));

    expect($repository->get()->stockOf(Product::Water))->toBe(2);
});

it('rejects an unknown product and changes nothing', function () {
    $repository = emptyMachineRepository();
    $repository->get()->setStock(Product::Water, 1);
    $handler = new ServiceMachineHandler($repository);

    expect(fn () => $handler(new ServiceMachineCommand(productCounts: ['WATER' => 9, 'COLA' => 5])))
        ->toThrow(UnknownProductException::class);
    // The valid WATER entry must not have been applied — servicing is atomic.
    expect($repository->get()->stockOf(Product::Water))->toBe(1);
});

it('rejects an invalid coin and changes nothing', function () {
    $repository = emptyMachineRepository();
    $handler = new ServiceMachineHandler($repository);

    expect(fn () => $handler(new ServiceMachineCommand(coinCounts: [25 => 4, 3 => 10])))
        ->toThrow(InvalidCoinException::class);
    expect($repository->get()->coinStockOf(Coin::TwentyFiveCents))->toBe(0);
});

it('refills a sold-out product so it can be bought again', function () {
    $repository = emptyMachineRepository();

    (new ServiceMachineHandler($repository))(new ServiceMachineCommand(
        productCounts: ['WATER' => 1],
        coinCounts: [25 => 1, 10 => 1],
    ));

    (new InsertCoinHandler($repository))(new InsertCoinCommand(100));
    $sale = (new BuyProductHandler($repository))(new BuyProductCommand('water'));

    expect($sale->productSelector)->toBe('WATER')
        ->and($sale->changeInCents)->toBe([25, 10]);
});
