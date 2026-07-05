<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

use DomainException;

final class OutOfStockException extends DomainException
{
    public static function forProduct(Product $product): self
    {
        return new self(sprintf('%s is out of stock.', $product->selector()));
    }
}
