<?php

declare(strict_types=1);

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\InsertedMoney;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Persistence\SqliteVendingMachineRepository;

/*
 * Integration tests for the real SQLite adapter. A temp-file database is used
 * per test (not :memory:) because the point is a genuine cross-connection
 * round-trip: state saved through one connection, read back through another.
 */

function schemaPath(): string
{
    return dirname(__DIR__, 4) . '/data/schema.sql';
}

function seedPath(): string
{
    return dirname(__DIR__, 4) . '/data/seed.sql';
}

function repositoryOn(string $dbPath): SqliteVendingMachineRepository
{
    return new SqliteVendingMachineRepository(new PDO('sqlite:' . $dbPath), schemaPath());
}

/**
 * Runs the test against a fresh temp-file database, cleaned up afterwards even
 * if an assertion fails.
 */
function withTempDatabase(Closure $test): void
{
    $dbPath = (string) tempnam(sys_get_temp_dir(), 'vm_') . '.sqlite';

    try {
        $test($dbPath);
    } finally {
        @unlink($dbPath);
    }
}

it('round-trips the whole machine state across connections', function () {
    withTempDatabase(function (string $dbPath) {
        $machine = VendingMachine::restore(
            ProductCatalogue::empty()
                ->withProduct(Product::new('WATER', 65))
                ->withProduct(Product::new('SODA', 150)),
            Inventory::empty()->withStock('WATER', 4)->withStock('SODA', 2),
            CoinBank::empty()->withCoins(Coin::OneEuro, 3)->withCoins(Coin::TenCents, 6),
            InsertedMoney::fromCounts([25 => 1, 5 => 2]),
        );

        repositoryOn($dbPath)->save($machine);

        // A brand-new repository instance on the same file — a different process.
        $reloaded = repositoryOn($dbPath)->get();

        expect($reloaded->catalogue()->get('WATER')->price())->toBe(65)
            ->and($reloaded->catalogue()->get('SODA')->price())->toBe(150)
            ->and($reloaded->stockOf('WATER'))->toBe(4)
            ->and($reloaded->stockOf('SODA'))->toBe(2)
            ->and($reloaded->coinStockOf(Coin::OneEuro))->toBe(3)
            ->and($reloaded->coinStockOf(Coin::TenCents))->toBe(6)
            ->and($reloaded->insertedBalance())->toBe(35);
    });
});

it('auto-creates the schema on a fresh database and returns an empty machine', function () {
    withTempDatabase(function (string $dbPath) {
        $machine = repositoryOn($dbPath)->get();

        expect($machine->catalogue()->all())->toBe([])
            ->and($machine->insertedBalance())->toBe(0)
            ->and($machine->changeAvailable())->toBe(0);
    });
});

it('replaces the whole state on save, leaving no stale rows', function () {
    withTempDatabase(function (string $dbPath) {
        $repository = repositoryOn($dbPath);

        $repository->save(VendingMachine::restore(
            ProductCatalogue::empty()
                ->withProduct(Product::new('WATER', 65))
                ->withProduct(Product::new('SODA', 150)),
            Inventory::empty()->withStock('WATER', 5)->withStock('SODA', 5),
            CoinBank::empty()->withCoins(Coin::OneEuro, 10),
            InsertedMoney::none(),
        ));

        $repository->save(VendingMachine::restore(
            ProductCatalogue::empty()->withProduct(Product::new('WATER', 65)),
            Inventory::empty()->withStock('WATER', 1),
            CoinBank::empty(),
            InsertedMoney::none(),
        ));

        $reloaded = repositoryOn($dbPath)->get();

        expect($reloaded->catalogue()->all())->toHaveCount(1)
            ->and($reloaded->catalogue()->has('SODA'))->toBeFalse()
            ->and($reloaded->stockOf('WATER'))->toBe(1)
            ->and($reloaded->changeAvailable())->toBe(0);
    });
});

it('boots the default catalogue when seeded from seed.sql', function () {
    withTempDatabase(function (string $dbPath) {
        repositoryOn($dbPath); // constructs the schema
        (new PDO('sqlite:' . $dbPath))->exec((string) file_get_contents(seedPath()));

        $machine = repositoryOn($dbPath)->get();

        expect($machine->catalogue()->get('WATER')->price())->toBe(65)
            ->and($machine->catalogue()->get('JUICE')->price())->toBe(100)
            ->and($machine->catalogue()->get('SODA')->price())->toBe(150)
            ->and($machine->stockOf('WATER'))->toBe(5)
            ->and($machine->coinStockOf(Coin::OneEuro))->toBe(10)
            ->and($machine->changeAvailable())->toBe(1800);
    });
});
