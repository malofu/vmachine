<?php

declare(strict_types=1);

use VendingMachine\Application\RemoveProduct\RemoveProductCommand;
use VendingMachine\Application\RemoveProduct\RemoveProductHandler;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\ProductInStockException;
use VendingMachine\Domain\UnknownProductException;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

function removeRepository(int $waterStock): InMemoryVendingMachineRepository
{
    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked(
        ProductCatalogue::empty()->withProduct(Product::new('WATER', 65)),
        Inventory::empty()->withStock('WATER', $waterStock),
        CoinBank::empty(),
    ));

    return $repository;
}

it('removes a product that has no stock', function () {
    $repository = removeRepository(waterStock: 0);

    (new RemoveProductHandler($repository))(new RemoveProductCommand('water'));

    expect($repository->get()->catalogue()->has('WATER'))->toBeFalse();
});

it('rejects removing a product the machine does not sell', function () {
    $repository = removeRepository(waterStock: 0);
    $handler = new RemoveProductHandler($repository);

    expect(fn () => $handler(new RemoveProductCommand('COLA')))->toThrow(UnknownProductException::class);
});

it('refuses to remove a product that still has stock and changes nothing', function () {
    $repository = removeRepository(waterStock: 3);
    $handler = new RemoveProductHandler($repository);

    expect(fn () => $handler(new RemoveProductCommand('WATER')))->toThrow(ProductInStockException::class);
    expect($repository->get()->catalogue()->has('WATER'))->toBeTrue()
        ->and($repository->get()->stockOf('WATER'))->toBe(3);
});
