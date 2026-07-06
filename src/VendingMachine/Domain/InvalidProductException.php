<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

use DomainException;

final class InvalidProductException extends DomainException
{
    public static function emptySelector(): self
    {
        return new self('A product needs a non-empty selector.');
    }

    public static function nonPositivePrice(int $priceInCents): self
    {
        return new self(sprintf('A product price must be positive, got %d cents.', $priceInCents));
    }
}
