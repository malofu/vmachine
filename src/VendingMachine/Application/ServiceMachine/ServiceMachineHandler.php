<?php

declare(strict_types=1);

namespace VendingMachine\Application\ServiceMachine;

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Services the machine: sets item counts and coin counts to the values the
 * technician asks for, then reports the resulting state.
 *
 * The whole command is validated before anything is applied, so an unknown
 * product or invalid coin leaves the machine untouched — servicing is atomic.
 * An empty command applies nothing and simply returns the current report, which
 * is how the technician view is rendered without a second use case.
 */
final readonly class ServiceMachineHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(ServiceMachineCommand $command): MachineReport
    {
        // Resolve every selector and denomination first; a bad entry throws here,
        // before the machine has been touched.
        $stockLevels = [];
        foreach ($command->productCounts as $selector => $count) {
            $stockLevels[] = [Product::fromSelector($selector), $count];
        }

        $changeLevels = [];
        foreach ($command->coinCounts as $cents => $count) {
            $changeLevels[] = [Coin::fromCents($cents), $count];
        }

        $machine = $this->machines->get();

        foreach ($stockLevels as [$product, $count]) {
            $machine->setStock($product, $count);
        }

        foreach ($changeLevels as [$coin, $count]) {
            $machine->setChange($coin, $count);
        }

        $this->machines->save($machine);

        return $this->report($machine);
    }

    private function report(VendingMachine $machine): MachineReport
    {
        $products = array_map(
            static fn (Product $product): ProductStock => new ProductStock(
                $product->selector(),
                $product->price(),
                $machine->stockOf($product),
            ),
            Product::cases(),
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
