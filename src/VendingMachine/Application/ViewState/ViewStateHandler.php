<?php

declare(strict_types=1);

namespace VendingMachine\Application\ViewState;

use VendingMachine\Domain\Product;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Reports the current state of the machine: the customer's inserted balance and,
 * for every product in the catalogue, its price and remaining stock.
 *
 * This is a pure query — it never mutates the machine, so nothing is saved. The
 * catalogue is sourced from the Product enum so the domain stays the single
 * authority on what the machine sells.
 */
final readonly class ViewStateHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(ViewStateCommand $command): ViewStateResponse
    {
        $machine = $this->machines->get();

        $products = array_map(
            static fn (Product $product): ProductState => new ProductState(
                $product->selector(),
                $product->price(),
                $machine->stockOf($product),
            ),
            Product::cases(),
        );

        return new ViewStateResponse($machine->insertedBalance(), $products);
    }
}
