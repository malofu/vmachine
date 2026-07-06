<?php

declare(strict_types=1);

use VendingMachine\Application\ServiceMachine\ServiceReportHandler;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

it('reports each catalogue product with its price and count, plus coins and total change', function () {
    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked(
        ProductCatalogue::empty()->withProduct(Product::new('WATER', 65)),
        Inventory::empty()->withStock('WATER', 5),
        CoinBank::empty()
            ->withCoins(Coin::TwentyFiveCents, 8)
            ->withCoins(Coin::OneEuro, 2),
    ));

    $report = (new ServiceReportHandler($repository))();

    [$water] = $report->products;
    expect($water->selector)->toBe('WATER')
        ->and($water->priceInCents)->toBe(65)
        ->and($water->count)->toBe(5)
        ->and($report->changeTotalInCents)->toBe(400);
});

it('reports no products for an empty catalogue', function () {
    $repository = new InMemoryVendingMachineRepository();
    $repository->save(VendingMachine::stocked(
        ProductCatalogue::empty(),
        Inventory::empty(),
        CoinBank::empty(),
    ));

    $report = (new ServiceReportHandler($repository))();

    expect($report->products)->toBe([]);
});
