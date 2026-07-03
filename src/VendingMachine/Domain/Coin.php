<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The set of coins the machine accepts, expressed in cents.
 *
 * Being an enum makes an invalid denomination unrepresentable: the only way to
 * obtain a Coin is through one of these cases, so the rest of the domain never
 * has to defend against a bad value.
 */
enum Coin: int
{
    case FiveCents = 5;
    case TenCents = 10;
    case TwentyFiveCents = 25;
    case OneEuro = 100;

    public static function fromCents(int $cents): self
    {
        return self::tryFrom($cents) ?? throw InvalidCoinException::forCents($cents);
    }

    public function cents(): int
    {
        return $this->value;
    }
}
