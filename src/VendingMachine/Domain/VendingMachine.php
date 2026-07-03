<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The machine as a whole. For this slice it only holds the money inserted so
 * far; later stories will extend it with the inventory and the coin bank.
 */
final class VendingMachine
{
    private function __construct(private InsertedMoney $insertedMoney)
    {
    }

    public static function new(): self
    {
        return new self(InsertedMoney::none());
    }

    public function insertCoin(Coin $coin): void
    {
        $this->insertedMoney = $this->insertedMoney->add($coin);
    }

    public function insertedBalance(): int
    {
        return $this->insertedMoney->total();
    }
}
