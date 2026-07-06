<?php

declare(strict_types=1);

namespace VendingMachine\Application\SetProductPrice;

use VendingMachine\Domain\Product;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Defines a product or reprices an existing one. Building the {@see Product}
 * guards its invariants (non-empty selector, positive price); an invalid one
 * throws before the machine is touched, so the action is atomic.
 */
final readonly class SetProductPriceHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(SetProductPriceCommand $command): void
    {
        $machine = $this->machines->get();
        $machine->setProduct(Product::new($command->selector, $command->priceInCents));
        $this->machines->save($machine);
    }
}
