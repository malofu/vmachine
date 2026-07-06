<?php

declare(strict_types=1);

namespace VendingMachine\Application\RemoveProduct;

use VendingMachine\Domain\VendingMachineRepository;

/**
 * Removes a product from the catalogue. The machine rejects an unknown selector
 * (UnknownProductException) or a product that still has stock
 * (ProductInStockException) before mutating, so the action is atomic.
 */
final readonly class RemoveProductHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(RemoveProductCommand $command): void
    {
        $machine = $this->machines->get();
        $machine->removeProduct($command->selector);
        $this->machines->save($machine);
    }
}
