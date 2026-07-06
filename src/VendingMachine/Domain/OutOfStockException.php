<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

use DomainException;

final class OutOfStockException extends DomainException
{
    public static function forProduct(Product $product): self
    {
        return self::forSelector($product->selector());
    }

    public static function forSelector(string $selector): self
    {
        return new self(sprintf('%s is out of stock.', $selector));
    }
}
