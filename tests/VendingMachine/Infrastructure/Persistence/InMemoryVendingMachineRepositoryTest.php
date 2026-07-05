<?php

declare(strict_types=1);

use VendingMachine\Domain\Coin;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

it('returns a fresh machine on first access', function () {
    $repository = new InMemoryVendingMachineRepository();

    expect($repository->get()->insertedBalance())->toBe(0);
});

it('persists a saved machine across gets', function () {
    $repository = new InMemoryVendingMachineRepository();

    $machine = $repository->get();
    $machine->insertCoin(Coin::OneEuro);
    $repository->save($machine);

    expect($repository->get()->insertedBalance())->toBe(100);
});
