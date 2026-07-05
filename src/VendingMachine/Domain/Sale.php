<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The outcome of a successful purchase: the product to dispense and the coins
 * of change owed to the customer (empty when they paid the exact amount).
 */
final class Sale
{
    /**
     * @param list<Coin> $change
     */
    public function __construct(
        private readonly Product $product,
        private readonly array $change,
    ) {
    }

    public function product(): Product
    {
        return $this->product;
    }

    /**
     * @return list<Coin>
     */
    public function change(): array
    {
        return $this->change;
    }
}
