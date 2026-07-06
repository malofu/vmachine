<?php

declare(strict_types=1);

namespace VendingMachine\Application\SetChange;

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Sets how many coins of a denomination the machine holds for change. Resolving
 * the denomination rejects an invalid one (InvalidCoinException) before the
 * machine is touched, so the action is atomic.
 */
final readonly class SetChangeHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(SetChangeCommand $command): void
    {
        $machine = $this->machines->get();
        $machine->setChange(Coin::fromCents($command->cents), $command->count);
        $this->machines->save($machine);
    }
}
