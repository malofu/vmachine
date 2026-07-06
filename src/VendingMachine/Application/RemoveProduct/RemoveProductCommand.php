<?php

declare(strict_types=1);

namespace VendingMachine\Application\RemoveProduct;

/**
 * The technician removes a product from the catalogue, in a single atomic action.
 */
final readonly class RemoveProductCommand
{
    public function __construct(public string $selector)
    {
    }
}
