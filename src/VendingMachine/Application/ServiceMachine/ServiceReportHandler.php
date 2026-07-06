<?php

declare(strict_types=1);

namespace VendingMachine\Application\ServiceMachine;

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * The technician's read of the machine: exact per-product and per-denomination
 * counts and the total change held. A pure query — it never mutates the machine,
 * so nothing is saved. It is how the service `state` view is rendered and how
 * each service action echoes the resulting state.
 */
final readonly class ServiceReportHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(): MachineReport
    {
        $machine = $this->machines->get();

        $products = array_map(
            static fn (Product $product): ProductStock => new ProductStock(
                $product->selector(),
                $product->price(),
                $machine->stockOf($product->selector()),
            ),
            $machine->catalogue()->all(),
        );

        $coins = array_map(
            static fn (Coin $coin): CoinStock => new CoinStock(
                $coin->cents(),
                $machine->coinStockOf($coin),
            ),
            Coin::cases(),
        );

        return new MachineReport($products, $coins, $machine->changeAvailable());
    }
}
