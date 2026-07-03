<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

use DomainException;

final class InvalidCoinException extends DomainException
{
    public static function forCents(int $cents): self
    {
        return new self(sprintf('%d cents is not an accepted coin.', $cents));
    }
}
