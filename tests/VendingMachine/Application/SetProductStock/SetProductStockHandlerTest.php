<?php

declare(strict_types=1);

use VendingMachine\Application\SetProductStock\SetProductStockCommand;
use VendingMachine\Application\SetProductStock\SetProductStockHandler;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\UnknownProductException;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

function stockRepository(): InMemoryVendingMachineRepository
{
    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked(
        ProductCatalogue::empty()->withProduct(Product::new('WATER', 65)),
        Inventory::empty(),
        CoinBank::empty(),
    ));

    return $repository;
}

it('sets a product stock to an absolute count', function () {
    $repository = stockRepository();

    (new SetProductStockHandler($repository))(new SetProductStockCommand('water', 5));

    expect($repository->get()->stockOf('WATER'))->toBe(5);
});

it('rejects stocking a product the machine does not sell', function () {
    $repository = stockRepository();
    $handler = new SetProductStockHandler($repository);

    expect(fn () => $handler(new SetProductStockCommand('COLA', 5)))->toThrow(UnknownProductException::class);
    expect($repository->get()->stockOf('COLA'))->toBe(0);
});
