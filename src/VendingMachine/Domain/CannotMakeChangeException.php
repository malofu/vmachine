<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

use DomainException;

final class CannotMakeChangeException extends DomainException
{
    private function __construct(string $message, private readonly int $changeInCents)
    {
        parent::__construct($message);
    }

    public static function forAmount(int $changeInCents): self
    {
        return new self(
            sprintf('The machine cannot compose %d cents of exact change.', $changeInCents),
            $changeInCents,
        );
    }

    public function changeInCents(): int
    {
        return $this->changeInCents;
    }
}
