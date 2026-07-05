<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * The products the machine sells, identified by their selector.
 *
 * Like {@see Coin}, this is an enum so an unknown product is unrepresentable:
 * the only way to obtain a Product is one of these cases. Each case owns its
 * price (in cents), keeping the catalogue and its prices in one place.
 */
enum Product: string
{
    case Water = 'WATER';
    case Juice = 'JUICE';
    case Soda = 'SODA';

    public static function fromSelector(string $selector): self
    {
        return self::tryFrom(strtoupper($selector))
            ?? throw UnknownProductException::forSelector($selector);
    }

    public function price(): int
    {
        return match ($this) {
            self::Water => 65,
            self::Juice => 100,
            self::Soda => 150,
        };
    }

    public function selector(): string
    {
        return $this->value;
    }
}
