<?php

declare(strict_types=1);

use VendingMachine\Application\InsertCoin\InsertCoinCommand;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Application\ReturnCoins\ReturnCoinsCommand;
use VendingMachine\Application\ReturnCoins\ReturnCoinsHandler;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

it('returns the inserted coins and resets the balance', function () {
    $repository = new InMemoryVendingMachineRepository();
    $insertCoin = new InsertCoinHandler($repository);
    $returnCoins = new ReturnCoinsHandler($repository);
    $insertCoin(new InsertCoinCommand(10));
    $insertCoin(new InsertCoinCommand(10));

    $response = $returnCoins(new ReturnCoinsCommand());

    expect($response->returnedCoinsInCents)->toBe([10, 10])
        ->and($repository->get()->insertedBalance())->toBe(0);
});

it('returns nothing when no coins were inserted', function () {
    $repository = new InMemoryVendingMachineRepository();
    $returnCoins = new ReturnCoinsHandler($repository);

    $response = $returnCoins(new ReturnCoinsCommand());

    expect($response->returnedCoinsInCents)->toBe([])
        ->and($repository->get()->insertedBalance())->toBe(0);
});
