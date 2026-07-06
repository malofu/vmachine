<?php

declare(strict_types=1);

use VendingMachine\Application\SetProductPrice\SetProductPriceCommand;
use VendingMachine\Application\SetProductPrice\SetProductPriceHandler;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\InvalidProductException;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

function priceRepository(ProductCatalogue $catalogue): InMemoryVendingMachineRepository
{
    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked($catalogue, Inventory::empty(), CoinBank::empty()));

    return $repository;
}

it('defines a new product', function () {
    $repository = priceRepository(ProductCatalogue::empty());

    (new SetProductPriceHandler($repository))(new SetProductPriceCommand('cola', 125));

    expect($repository->get()->catalogue()->get('COLA')->price())->toBe(125);
});

it('reprices an existing product', function () {
    $repository = priceRepository(ProductCatalogue::empty()->withProduct(Product::new('WATER', 65)));

    (new SetProductPriceHandler($repository))(new SetProductPriceCommand('WATER', 70));

    expect($repository->get()->catalogue()->get('WATER')->price())->toBe(70);
});

it('rejects a non-positive price and changes nothing', function () {
    $repository = priceRepository(ProductCatalogue::empty()->withProduct(Product::new('WATER', 65)));
    $handler = new SetProductPriceHandler($repository);

    expect(fn () => $handler(new SetProductPriceCommand('WATER', 0)))->toThrow(InvalidProductException::class);
    expect($repository->get()->catalogue()->get('WATER')->price())->toBe(65);
});

it('rejects an empty selector', function () {
    $repository = priceRepository(ProductCatalogue::empty());
    $handler = new SetProductPriceHandler($repository);

    expect(fn () => $handler(new SetProductPriceCommand('   ', 65)))->toThrow(InvalidProductException::class);
    expect($repository->get()->catalogue()->all())->toBe([]);
});
