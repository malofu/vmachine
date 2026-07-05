<?php

declare(strict_types=1);

namespace VendingMachine\Application\ViewState;

/**
 * One line of the machine's catalogue as seen by a customer: what the product
 * is called, what it costs and how many are left.
 */
final readonly class ProductState
{
    public function __construct(
        public string $selector,
        public int $priceInCents,
        public int $stock,
    ) {
    }
}
