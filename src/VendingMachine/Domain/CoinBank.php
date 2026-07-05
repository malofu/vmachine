<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The coins the machine holds and uses to give change.
 *
 * An immutable value object keyed by denomination (in cents). Both depositing a
 * customer's payment and withdrawing change return a new instance.
 *
 * Change is composed with a count-aware search rather than a greedy pass: a
 * greedy "largest coin first" strategy can wrongly report that change is
 * impossible when it is in fact composable from smaller coins (e.g. giving 0.30
 * from one 0.25 and three 0.10 — greedy takes the 0.25 and then has no 0.05).
 * With only four denominations the exhaustive search is effectively free, and
 * it never short-changes nor falsely refuses a sale.
 */
final class CoinBank
{
    /**
     * @param array<int, int> $coins denomination in cents => count held
     */
    private function __construct(private readonly array $coins)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Sets the number of coins held for a denomination, replacing any previous
     * value. Used to load the machine's change (later, by the service technician).
     */
    public function withCoins(Coin $coin, int $count): self
    {
        $coins = $this->coins;
        $coins[$coin->cents()] = max(0, $count);

        return new self($coins);
    }

    /**
     * @param list<Coin> $deposited
     */
    public function deposit(array $deposited): self
    {
        $coins = $this->coins;

        foreach ($deposited as $coin) {
            $coins[$coin->cents()] = ($coins[$coin->cents()] ?? 0) + 1;
        }

        return new self($coins);
    }

    public function total(): int
    {
        $total = 0;

        foreach ($this->coins as $cents => $count) {
            $total += $cents * $count;
        }

        return $total;
    }

    /**
     * Attempts to take exactly $amount out of the bank as coins.
     *
     * Returns the reduced bank together with the withdrawn coins, or null when
     * the exact amount cannot be composed from the coins currently held. A zero
     * amount always succeeds with no coins.
     *
     * @return array{self, list<Coin>}|null
     */
    public function withdraw(int $amount): ?array
    {
        $change = $this->compose($amount, $this->coins);

        if ($change === null) {
            return null;
        }

        $coins = $this->coins;

        foreach ($change as $coin) {
            $coins[$coin->cents()] -= 1;
        }

        return [new self($coins), $change];
    }

    /**
     * Finds a set of held coins summing to exactly $amount, preferring larger
     * denominations first (so change comes back in the fewest coins). Returns
     * the coins, or null if no combination fits within the available counts.
     *
     * @param array<int, int> $available denomination => count still usable
     * @return list<Coin>|null
     */
    private function compose(int $amount, array $available): ?array
    {
        if ($amount === 0) {
            return [];
        }

        foreach ($this->denominationsHighToLow() as $cents) {
            if ($cents > $amount || ($available[$cents] ?? 0) < 1) {
                continue;
            }

            $remaining = $available;
            $remaining[$cents] -= 1;

            $rest = $this->compose($amount - $cents, $remaining);

            if ($rest !== null) {
                return [Coin::fromCents($cents), ...$rest];
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function denominationsHighToLow(): array
    {
        $cents = array_map(static fn (Coin $coin): int => $coin->cents(), Coin::cases());
        rsort($cents);

        return $cents;
    }
}
