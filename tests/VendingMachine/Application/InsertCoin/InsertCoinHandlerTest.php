<?php

declare(strict_types=1);

use VendingMachine\Application\InsertCoin\InsertCoinCommand;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Domain\InvalidCoinException;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

it('inserts a valid coin and reports the new balance', function () {
    $repository = new InMemoryVendingMachineRepository();
    $handler = new InsertCoinHandler($repository);

    $response = $handler(new InsertCoinCommand(25));

    expect($response->balanceInCents)->toBe(25)
        ->and($repository->get()->insertedBalance())->toBe(25);
});

it('accumulates the balance across several inserts', function () {
    $repository = new InMemoryVendingMachineRepository();
    $handler = new InsertCoinHandler($repository);

    $handler(new InsertCoinCommand(100));
    $response = $handler(new InsertCoinCommand(25));

    expect($response->balanceInCents)->toBe(125);
});

it('rejects an invalid coin and leaves the balance untouched', function () {
    $repository = new InMemoryVendingMachineRepository();
    $handler = new InsertCoinHandler($repository);
    $handler(new InsertCoinCommand(25));

    expect(fn () => $handler(new InsertCoinCommand(3)))->toThrow(InvalidCoinException::class);
    expect($repository->get()->insertedBalance())->toBe(25);
});
