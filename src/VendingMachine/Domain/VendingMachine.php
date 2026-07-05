<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The machine as a whole: it holds the money the customer has inserted, the
 * product inventory and the coin bank, and enforces the rules of a sale.
 */
final class VendingMachine
{
    private function __construct(
        private InsertedMoney $insertedMoney,
        private Inventory $inventory,
        private CoinBank $coinBank,
    ) {
    }

    public static function new(): self
    {
        return new self(InsertedMoney::none(), Inventory::empty(), CoinBank::empty());
    }

    /**
     * Builds a machine already loaded with stock and change. The seam through
     * which the machine is initially provisioned (today from the CLI bootstrap,
     * later by the service technician).
     */
    public static function stocked(Inventory $inventory, CoinBank $coinBank): self
    {
        return new self(InsertedMoney::none(), $inventory, $coinBank);
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
     * The rules are checked in order and the machine is only mutated once all
     * of them hold, so a failed purchase leaves the inserted money untouched —
     * the customer keeps their credit and can retry or ask for it back.
     *
     * @throws OutOfStockException          when the product is sold out
     * @throws InsufficientMoneyException   when the inserted money is too little
     * @throws CannotMakeChangeException    when exact change cannot be composed
     */
    public function buy(Product $product): Sale
    {
        if (!$this->inventory->has($product)) {
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
        $this->inventory = $this->inventory->dispense($product);
        $this->insertedMoney = InsertedMoney::none();

        return new Sale($product, $change);
    }

    public function insertedBalance(): int
    {
        return $this->insertedMoney->total();
    }

    public function stockOf(Product $product): int
    {
        return $this->inventory->countOf($product);
    }

    public function changeAvailable(): int
    {
        return $this->coinBank->total();
    }
}
