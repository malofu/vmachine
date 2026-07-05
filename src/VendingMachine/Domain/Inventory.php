<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The stock the machine holds: how many of each product remain.
 *
 * An immutable value object keyed by the product selector. Dispensing returns a
 * new instance with one fewer of the given product, never mutating in place.
 */
final class Inventory
{
    /**
     * @param array<string, int> $stock product selector => remaining count
     */
    private function __construct(private readonly array $stock)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Sets the count held for a product, replacing any previous value. Used to
     * stock the machine (later, by the service technician).
     */
    public function withStock(Product $product, int $count): self
    {
        $stock = $this->stock;
        $stock[$product->selector()] = max(0, $count);

        return new self($stock);
    }

    public function has(Product $product): bool
    {
        return $this->countOf($product) > 0;
    }

    public function countOf(Product $product): int
    {
        return $this->stock[$product->selector()] ?? 0;
    }

    /**
     * Hands out one unit of the product, returning the reduced inventory.
     *
     * Guards its own invariant: the aggregate is expected to check {@see has()}
     * first, so reaching an empty slot here means a rule was skipped.
     */
    public function dispense(Product $product): self
    {
        if (!$this->has($product)) {
            throw OutOfStockException::forProduct($product);
        }

        $stock = $this->stock;
        $stock[$product->selector()] -= 1;

        return new self($stock);
    }
}
