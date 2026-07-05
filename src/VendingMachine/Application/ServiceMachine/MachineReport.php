<?php

declare(strict_types=1);

namespace VendingMachine\Application\ServiceMachine;

/**
 * The technician's read model: exact per-product and per-denomination counts and
 * the total change held. The counts-carrying counterpart to the customer's
 * availability-only ProductState.
 */
final readonly class MachineReport
{
    /**
     * @param list<ProductStock> $products in the machine's catalogue order
     * @param list<CoinStock>    $coins    in denomination order
     */
    public function __construct(
        public array $products,
        public array $coins,
        public int $changeTotalInCents,
    ) {
    }
}
