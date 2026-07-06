<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The machine as a whole: it holds the money the customer has inserted, the
 * product catalogue, the stock inventory and the coin bank, and enforces the
 * rules of a sale.
 */
final class VendingMachine
{
    private function __construct(
        private InsertedMoney $insertedMoney,
        private ProductCatalogue $catalogue,
        private Inventory $inventory,
        private CoinBank $coinBank,
    ) {
    }

    public static function new(): self
    {
        return new self(
            InsertedMoney::none(),
            ProductCatalogue::empty(),
            Inventory::empty(),
            CoinBank::empty(),
        );
    }

    /**
     * Builds a machine already loaded with a catalogue, stock and change. The
     * seam through which the machine is initially provisioned (today from the CLI
     * bootstrap, and by the service technician).
     */
    public static function stocked(ProductCatalogue $catalogue, Inventory $inventory, CoinBank $coinBank): self
    {
        return new self(InsertedMoney::none(), $catalogue, $inventory, $coinBank);
    }

    public function insertCoin(Coin $coin): void
    {
        $this->insertedMoney = $this->insertedMoney->add($coin);
    }

    /**
     * Hands back everything inserted so far and leaves the machine with an
     * empty balance, ready for the next customer.
     */
    public function returnCoins(): InsertedMoney
    {
        $returned = $this->insertedMoney;
        $this->insertedMoney = InsertedMoney::none();

        return $returned;
    }

    /**
     * Sells a product: dispenses it and returns any change owed.
     *
     * Resolving the selector is the first sale rule — an unknown selection is
     * rejected here. The remaining rules are checked in order and the machine is
     * only mutated once all of them hold, so a failed purchase leaves the
     * inserted money untouched — the customer keeps their credit and can retry or
     * ask for it back.
     *
     * @throws UnknownProductException       when the selector names no product
     * @throws OutOfStockException           when the product is sold out
     * @throws InsufficientMoneyException    when the inserted money is too little
     * @throws CannotMakeChangeException     when exact change cannot be composed
     */
    public function buy(string $selector): Sale
    {
        $product = $this->catalogue->get($selector);

        if (!$this->inventory->has($product->selector())) {
            throw OutOfStockException::forProduct($product);
        }

        $inserted = $this->insertedMoney->total();

        if ($inserted < $product->price()) {
            throw InsufficientMoneyException::forProduct($product, $inserted);
        }

        // The payment joins the bank first, so the customer's own coins are
        // available to make up their change.
        $bankWithPayment = $this->coinBank->deposit($this->insertedMoney->coins());
        $withdrawal = $bankWithPayment->withdraw($inserted - $product->price());

        if ($withdrawal === null) {
            throw CannotMakeChangeException::forAmount($inserted - $product->price());
        }

        [$this->coinBank, $change] = $withdrawal;
        $this->inventory = $this->inventory->dispense($product->selector());
        $this->insertedMoney = InsertedMoney::none();

        return new Sale($product, $change);
    }

    public function insertedBalance(): int
    {
        return $this->insertedMoney->total();
    }

    /**
     * The catalogue of products the machine sells, for read models and the
     * technician view.
     */
    public function catalogue(): ProductCatalogue
    {
        return $this->catalogue;
    }

    /**
     * Whether the product can be bought right now. This is what a customer is
     * shown; the exact remaining count is stock detail the service technician
     * cares about, not the customer.
     */
    public function isAvailable(string $selector): bool
    {
        return $this->inventory->has($selector);
    }

    public function stockOf(string $selector): int
    {
        return $this->inventory->countOf($selector);
    }

    public function coinStockOf(Coin $coin): int
    {
        return $this->coinBank->countOf($coin);
    }

    public function changeAvailable(): int
    {
        return $this->coinBank->total();
    }

    /**
     * Defines a product or reprices an existing one. The service technician's
     * operation, gated at the adapter; the aggregate trusts it is only reached by
     * a servicer.
     */
    public function setProduct(Product $product): void
    {
        $this->catalogue = $this->catalogue->withProduct($product);
    }

    /**
     * Removes a product from the catalogue. Refused while the slot still holds
     * stock — the technician must empty it first, so product that is physically
     * in the machine is never silently discarded.
     *
     * @throws UnknownProductException   when the selector names no product
     * @throws ProductInStockException   when the product still has stock
     */
    public function removeProduct(string $selector): void
    {
        $product = $this->catalogue->get($selector);

        if ($this->inventory->countOf($product->selector()) > 0) {
            throw ProductInStockException::forSelector($product->selector());
        }

        $this->catalogue = $this->catalogue->withoutProduct($product->selector());
        $this->inventory = $this->inventory->without($product->selector());
    }

    /**
     * Refills a product to an absolute count. A servicing operation; the product
     * must already be in the catalogue, so a slot cannot be stocked for something
     * the machine does not sell.
     *
     * @throws UnknownProductException when the selector names no product
     */
    public function setStock(string $selector, int $count): void
    {
        $product = $this->catalogue->get($selector);
        $this->inventory = $this->inventory->withStock($product->selector(), $count);
    }

    /**
     * Sets the number of coins of a denomination the machine holds for change,
     * to an absolute count. Like {@see setStock()}, a servicing operation.
     */
    public function setChange(Coin $coin, int $count): void
    {
        $this->coinBank = $this->coinBank->withCoins($coin, $count);
    }
}
