<?php

declare(strict_types=1);

namespace VendingMachine\Application\ViewState;

/**
 * One line of the machine's catalogue as seen by a customer: what the product
 * is called, what it costs and whether it can be bought right now.
 *
 * A customer sees availability, not the remaining count — the exact stock is
 * detail for the service technician, so it deliberately never reaches here.
 */
final readonly class ProductState
{
    public function __construct(
        public string $selector,
        public int $priceInCents,
        public bool $available,
    ) {
    }
}
