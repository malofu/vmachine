<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * A product the machine sells: a selector and a price (in cents).
 *
 * Products used to be a closed enum; they are now runtime data the technician
 * manages, so a Product is an immutable value object built through {@see new()},
 * which guards the invariants an enum case gave us for free — a non-empty,
 * normalized selector and a positive price. The set of products a machine
 * actually sells lives in the {@see ProductCatalogue}.
 */
final class Product
{
    private function __construct(
        private readonly string $selector,
        private readonly int $priceInCents,
    ) {
    }

    public static function new(string $selector, int $priceInCents): self
    {
        $selector = strtoupper(trim($selector));

        if ($selector === '') {
            throw InvalidProductException::emptySelector();
        }

        if ($priceInCents <= 0) {
            throw InvalidProductException::nonPositivePrice($priceInCents);
        }

        return new self($selector, $priceInCents);
    }

    public function selector(): string
    {
        return $this->selector;
    }

    public function price(): int
    {
        return $this->priceInCents;
    }
}
