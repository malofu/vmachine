<?php

declare(strict_types=1);

namespace VendingMachine\Application\ServiceMachine;

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductInStockException;
use VendingMachine\Domain\UnknownProductException;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Services the machine: defines and reprices products, removes them, and sets
 * item and coin counts to the values the technician asks for, then reports the
 * resulting state.
 *
 * The whole command is validated before anything is applied, so an invalid
 * product, an unknown selector or an invalid coin leaves the machine untouched —
 * servicing is atomic. (The in-memory machine is mutated in place, so validating
 * up front, before the first mutation, is what makes atomicity hold.) An empty
 * command applies nothing and simply returns the current report, which is how
 * the technician view is rendered without a second use case.
 */
final readonly class ServiceMachineHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(ServiceMachineCommand $command): MachineReport
    {
        $machine = $this->machines->get();
        $catalogue = $machine->catalogue();

        // Validate the whole command first, touching nothing. Product prices
        // become value objects (guarding their invariants); every stock and
        // removal selector must name a product that either already exists or is
        // being defined in this same command; every denomination must be valid.
        $products = [];
        foreach ($command->productPrices as $selector => $priceInCents) {
            $products[] = Product::new($selector, $priceInCents);
        }

        $definable = $this->knownSelectors($catalogue->all(), $products);

        $stockLevels = [];
        foreach ($command->productCounts as $selector => $count) {
            $selector = strtoupper(trim((string) $selector));
            if (!isset($definable[$selector])) {
                throw UnknownProductException::forSelector($selector);
            }
            $stockLevels[$selector] = $count;
        }

        // Keyed by selector so a selector staged for removal twice is removed
        // once, not applied twice (the second call would throw mid-apply).
        $removals = [];
        foreach ($command->productRemovals as $selector) {
            $selector = strtoupper(trim($selector));
            if (!isset($definable[$selector])) {
                throw UnknownProductException::forSelector($selector);
            }
            // The removal must leave no stock behind: check the count this same
            // command will end up setting, falling back to the current stock.
            $remaining = $stockLevels[$selector] ?? $machine->stockOf($selector);
            if ($remaining > 0) {
                throw ProductInStockException::forSelector($selector);
            }
            $removals[$selector] = true;
        }

        $changeLevels = [];
        foreach ($command->coinCounts as $cents => $count) {
            $changeLevels[] = [Coin::fromCents($cents), $count];
        }

        // Everything resolved; apply in an order that lets one command both
        // define-and-stock a product and empty-and-remove another.
        foreach ($products as $product) {
            $machine->setProduct($product);
        }

        foreach ($stockLevels as $selector => $count) {
            $machine->setStock($selector, $count);
        }

        foreach (array_keys($removals) as $selector) {
            $machine->removeProduct((string) $selector);
        }

        foreach ($changeLevels as [$coin, $count]) {
            $machine->setChange($coin, $count);
        }

        $this->machines->save($machine);

        return $this->report($machine);
    }

    /**
     * The selectors that will exist while this command is applied: those already
     * in the catalogue plus those being defined now. Used to reject stock or
     * removals for products the machine neither sells nor is about to.
     *
     * @param list<Product> $catalogue
     * @param list<Product> $defined
     * @return array<string, true>
     */
    private function knownSelectors(array $catalogue, array $defined): array
    {
        $known = [];
        foreach ([...$catalogue, ...$defined] as $product) {
            $known[$product->selector()] = true;
        }

        return $known;
    }

    private function report(VendingMachine $machine): MachineReport
    {
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
