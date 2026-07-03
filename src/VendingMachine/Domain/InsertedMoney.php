<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The coins a customer has inserted so far, as an immutable value object.
 *
 * The individual coins are kept (not just a running total) so that returning
 * the money later can hand back the very coins that were inserted.
 */
final class InsertedMoney
{
    /**
     * @param list<Coin> $coins
     */
    private function __construct(private readonly array $coins)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function add(Coin $coin): self
    {
        return new self([...$this->coins, $coin]);
    }

    /**
     * @return list<Coin>
     */
    public function coins(): array
    {
        return $this->coins;
    }

    public function total(): int
    {
        return array_sum(array_map(static fn (Coin $coin): int => $coin->cents(), $this->coins));
    }
}
