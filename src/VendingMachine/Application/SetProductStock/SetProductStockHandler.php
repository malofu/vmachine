<?php

declare(strict_types=1);

namespace VendingMachine\Application\SetProductStock;

use VendingMachine\Domain\VendingMachineRepository;

/**
 * Sets a product's stock to an absolute count. The machine rejects a selector it
 * does not sell (UnknownProductException) before mutating, so the action is
 * atomic.
 */
final readonly class SetProductStockHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(SetProductStockCommand $command): void
    {
        $machine = $this->machines->get();
        $machine->setStock($command->selector, $command->count);
        $this->machines->save($machine);
    }
}
