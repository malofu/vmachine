<?php

declare(strict_types=1);

namespace VendingMachine\Application\InsertCoin;

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Inserts a single coin into the machine.
 *
 * The amount arrives as plain cents; turning it into a Coin is where an
 * unaccepted denomination is rejected (InvalidCoinException), before the
 * machine is ever touched.
 */
final readonly class InsertCoinHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(InsertCoinCommand $command): InsertCoinResponse
    {
        $coin = Coin::fromCents($command->amountInCents);

        $machine = $this->machines->get();
        $machine->insertCoin($coin);
        $this->machines->save($machine);

        return new InsertCoinResponse($machine->insertedBalance());
    }
}
