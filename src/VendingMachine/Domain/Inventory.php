<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The stock the machine holds: how many of each product remain.
 *
 * An immutable value object keyed by product selector — a pure count, with no
 * notion of price. Which products exist and what they cost is the separate
 * concern of the {@see ProductCatalogue}. Dispensing returns a new instance with
 * one fewer of the given product, never mutating in place.
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
     * stock the machine (by the service technician).
     */
    public function withStock(string $selector, int $count): self
    {
        $stock = $this->stock;
        $stock[strtoupper($selector)] = max(0, $count);

        return new self($stock);
    }

    /**
     * Drops a product's slot entirely, so a removed product leaves no orphan
     * count behind.
     */
    public function without(string $selector): self
    {
        $stock = $this->stock;
        unset($stock[strtoupper($selector)]);

        return new self($stock);
    }

    public function has(string $selector): bool
    {
        return $this->countOf($selector) > 0;
    }

    public function countOf(string $selector): int
    {
        return $this->stock[strtoupper($selector)] ?? 0;
    }

    /**
     * Hands out one unit of the product, returning the reduced inventory.
     *
     * Guards its own invariant: the aggregate is expected to check {@see has()}
     * first, so reaching an empty slot here means a rule was skipped.
     */
    public function dispense(string $selector): self
    {
        if (!$this->has($selector)) {
            throw OutOfStockException::forSelector($selector);
        }

        $stock = $this->stock;
        $stock[strtoupper($selector)] -= 1;

        return new self($stock);
    }
}
