<?php

declare(strict_types=1);

use VendingMachine\Application\InsertCoin\InsertCoinCommand;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Application\ViewState\ViewStateCommand;
use VendingMachine\Application\ViewState\ViewStateHandler;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

function stateRepository(): InMemoryVendingMachineRepository
{
    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked(
        Inventory::empty()
            ->withStock(Product::Water, 3)
            ->withStock(Product::Juice, 1)
            ->withStock(Product::Soda, 0),
        CoinBank::empty(),
    ));

    return $repository;
}

it('reports the inserted balance and every product with its price and stock', function () {
    $repository = stateRepository();
    (new InsertCoinHandler($repository))(new InsertCoinCommand(25));

    $response = (new ViewStateHandler($repository))(new ViewStateCommand());

    expect($response->balanceInCents)->toBe(25)
        ->and($response->products)->toHaveCount(3);

    [$water, $juice, $soda] = $response->products;

    expect($water->selector)->toBe('WATER')
        ->and($water->priceInCents)->toBe(65)
        ->and($water->stock)->toBe(3)
        ->and($juice->selector)->toBe('JUICE')
        ->and($juice->priceInCents)->toBe(100)
        ->and($juice->stock)->toBe(1)
        ->and($soda->selector)->toBe('SODA')
        ->and($soda->priceInCents)->toBe(150)
        ->and($soda->stock)->toBe(0);
});

it('reports a zero balance when no coins have been inserted', function () {
    $repository = stateRepository();

    $response = (new ViewStateHandler($repository))(new ViewStateCommand());

    expect($response->balanceInCents)->toBe(0);
});

it('does not alter the machine when viewing its state', function () {
    $repository = stateRepository();
    (new InsertCoinHandler($repository))(new InsertCoinCommand(25));

    (new ViewStateHandler($repository))(new ViewStateCommand());

    expect($repository->get()->insertedBalance())->toBe(25)
        ->and($repository->get()->stockOf(Product::Water))->toBe(3);
});
