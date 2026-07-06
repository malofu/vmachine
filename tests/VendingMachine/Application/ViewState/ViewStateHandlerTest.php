<?php

declare(strict_types=1);

use VendingMachine\Application\InsertCoin\InsertCoinCommand;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Application\ViewState\ViewStateCommand;
use VendingMachine\Application\ViewState\ViewStateHandler;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

function stateRepository(): InMemoryVendingMachineRepository
{
    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked(
        ProductCatalogue::empty()
            ->withProduct(Product::new('WATER', 65))
            ->withProduct(Product::new('JUICE', 100))
            ->withProduct(Product::new('SODA', 150)),
        Inventory::empty()
            ->withStock('WATER', 3)
            ->withStock('JUICE', 1)
            ->withStock('SODA', 0),
        CoinBank::empty(),
    ));

    return $repository;
}

it('reports the inserted balance and every product with its price and availability', function () {
    $repository = stateRepository();
    (new InsertCoinHandler($repository))(new InsertCoinCommand(25));

    $response = (new ViewStateHandler($repository))(new ViewStateCommand());

    expect($response->balanceInCents)->toBe(25)
        ->and($response->products)->toHaveCount(3);

    [$water, $juice, $soda] = $response->products;

    expect($water->selector)->toBe('WATER')
        ->and($water->priceInCents)->toBe(65)
        ->and($water->available)->toBeTrue()
        ->and($juice->selector)->toBe('JUICE')
        ->and($juice->priceInCents)->toBe(100)
        ->and($juice->available)->toBeTrue()
        ->and($soda->selector)->toBe('SODA')
        ->and($soda->priceInCents)->toBe(150)
        ->and($soda->available)->toBeFalse();
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
        ->and($repository->get()->stockOf('WATER'))->toBe(3);
});
