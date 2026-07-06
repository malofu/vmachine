<?php

declare(strict_types=1);

namespace VendingMachine\Application\SetProductStock;

/**
 * The technician sets a product's stock to an absolute count, in a single atomic
 * action.
 */
final readonly class SetProductStockCommand
{
    public function __construct(
        public string $selector,
        public int $count,
    ) {
    }
}
