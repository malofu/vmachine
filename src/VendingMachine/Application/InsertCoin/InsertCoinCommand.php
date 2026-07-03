<?php

declare(strict_types=1);

namespace VendingMachine\Application\InsertCoin;

final readonly class InsertCoinCommand
{
    public function __construct(public int $amountInCents)
    {
    }
}
