<?php

declare(strict_types=1);

namespace VendingMachine\Application\ReturnCoins;

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Returns every coin the customer has inserted so far and leaves the machine
 * with an empty balance.
 */
final readonly class ReturnCoinsHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(ReturnCoinsCommand $command): ReturnCoinsResponse
    {
        $machine = $this->machines->get();
        $returned = $machine->returnCoins();
        $this->machines->save($machine);

        return new ReturnCoinsResponse(array_map(
            static fn (Coin $coin): int => $coin->cents(),
            $returned->coins(),
        ));
    }
}
