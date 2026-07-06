<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

use DomainException;

final class ProductInStockException extends DomainException
{
    public static function forSelector(string $selector): self
    {
        return new self(sprintf('%s still has stock; empty the slot before removing it.', $selector));
    }
}
