<?php

declare(strict_types=1);

namespace VendingMachine\Application\SetProductPrice;

/**
 * The technician defines a product or reprices an existing one, in a single
 * atomic action.
 */
final readonly class SetProductPriceCommand
{
    public function __construct(
        public string $selector,
        public int $priceInCents,
    ) {
    }
}
