<?php

declare(strict_types=1);

use VendingMachine\Application\SetChange\SetChangeCommand;
use VendingMachine\Application\SetChange\SetChangeHandler;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\InvalidCoinException;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

function changeRepository(): InMemoryVendingMachineRepository
{
    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked(
        ProductCatalogue::empty(),
        Inventory::empty(),
        CoinBank::empty(),
    ));

    return $repository;
}

it('sets how many coins of a denomination the machine holds', function () {
    $repository = changeRepository();

    (new SetChangeHandler($repository))(new SetChangeCommand(25, 8));

    expect($repository->get()->coinStockOf(Coin::TwentyFiveCents))->toBe(8)
        ->and($repository->get()->changeAvailable())->toBe(200);
});

it('rejects an invalid denomination and changes nothing', function () {
    $repository = changeRepository();
    $handler = new SetChangeHandler($repository);

    expect(fn () => $handler(new SetChangeCommand(3, 10)))->toThrow(InvalidCoinException::class);
    expect($repository->get()->changeAvailable())->toBe(0);
});
