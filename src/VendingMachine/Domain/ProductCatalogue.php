<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The set of products the machine sells, each with its price — the "what can I
 * buy and for how much" of the machine.
 *
 * This is the catalogue only; how many of each remain is a separate concern held
 * by the {@see Inventory}. An immutable value object keyed by selector: adding a
 * product with an existing selector replaces it, which is how a reprice is
 * expressed. It is also where an unknown selection is rejected, the role the old
 * Product enum's fromSelector used to play.
 */
final class ProductCatalogue
{
    /**
     * @param array<string, Product> $products selector => product
     */
    private function __construct(private readonly array $products)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Adds a product, or replaces the one with the same selector (a reprice).
     */
    public function withProduct(Product $product): self
    {
        $products = $this->products;
        $products[$product->selector()] = $product;

        return new self($products);
    }

    public function withoutProduct(string $selector): self
    {
        $selector = strtoupper($selector);
        $product = $this->products[$selector] ?? throw UnknownProductException::forSelector($selector);

        $products = $this->products;
        unset($products[$product->selector()]);

        return new self($products);
    }

    public function get(string $selector): Product
    {
        return $this->products[strtoupper($selector)]
            ?? throw UnknownProductException::forSelector($selector);
    }

    public function has(string $selector): bool
    {
        return isset($this->products[strtoupper($selector)]);
    }

    /**
     * @return list<Product>
     */
    public function all(): array
    {
        return array_values($this->products);
    }
}
