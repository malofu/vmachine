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

    /**
     * Rebuilds the inserted money from a per-denomination tally, expanding each
     * count back into that many coins. The seam through which a repository
     * reconstitutes the balance: the sum is preserved exactly, but the original
     * insertion order is not (it is unobservable — only the total and the coins
     * held matter). See {@see counts()} for the inverse.
     *
     * @param array<int, int> $counts denomination in cents => count
     */
    public static function fromCounts(array $counts): self
    {
        $coins = [];

        foreach ($counts as $cents => $count) {
            for ($i = 0; $i < $count; $i++) {
                $coins[] = Coin::fromCents($cents);
            }
        }

        return new self($coins);
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

    /**
     * The inserted coins tallied per denomination, for a repository to snapshot.
     * The inverse of {@see fromCounts()}; insertion order is intentionally
     * dropped, since it is never observable.
     *
     * @return array<int, int> denomination in cents => count
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->coins as $coin) {
            $counts[$coin->cents()] = ($counts[$coin->cents()] ?? 0) + 1;
        }

        return $counts;
    }
}
