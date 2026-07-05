<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

use DomainException;

final class InsufficientMoneyException extends DomainException
{
    private function __construct(
        string $message,
        private readonly int $priceInCents,
        private readonly int $insertedInCents,
    ) {
        parent::__construct($message);
    }

    public static function forProduct(Product $product, int $insertedInCents): self
    {
        return new self(
            sprintf(
                '%s costs %d cents but only %d were inserted.',
                $product->selector(),
                $product->price(),
                $insertedInCents,
            ),
            $product->price(),
            $insertedInCents,
        );
    }

    public function priceInCents(): int
    {
        return $this->priceInCents;
    }

    public function insertedInCents(): int
    {
        return $this->insertedInCents;
    }
}
