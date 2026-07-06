<?php

declare(strict_types=1);

namespace VendingMachine\Application\ServiceMachine;

/**
 * A single servicing action: the technician opens the machine and, in one atomic
 * step, defines or reprices products, removes products, and sets item and coin
 * counts to absolute values. Every field is optional (an empty command applies
 * nothing and simply reports the current state).
 *
 * Products are applied before stock, so a product can be defined and stocked in
 * the same command; removals come after stock, so a slot can be emptied and
 * removed together.
 */
final readonly class ServiceMachineCommand
{
    /**
     * @param array<string, int> $productPrices  product selector => price in cents (define or reprice)
     * @param array<string, int> $productCounts  product selector => absolute count
     * @param list<string>       $productRemovals product selectors to remove
     * @param array<int, int>    $coinCounts     denomination in cents => absolute count
     */
    public function __construct(
        public array $productPrices = [],
        public array $productCounts = [],
        public array $productRemovals = [],
        public array $coinCounts = [],
    ) {
    }
}
