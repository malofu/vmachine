<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

use DomainException;

final class UnknownProductException extends DomainException
{
    public static function forSelector(string $selector): self
    {
        return new self(sprintf('"%s" is not a product this machine sells.', $selector));
    }
}
