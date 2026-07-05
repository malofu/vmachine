<?php

declare(strict_types=1);

namespace VendingMachine\Application\ServiceMachine;

/**
 * One product line of the technician's view: the exact remaining count — the
 * detail deliberately kept from customers, who only ever see availability.
 */
final readonly class ProductStock
{
    public function __construct(
        public string $selector,
        public int $priceInCents,
        public int $count,
    ) {
    }
}
