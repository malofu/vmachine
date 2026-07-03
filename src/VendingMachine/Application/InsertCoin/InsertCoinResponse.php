<?php

declare(strict_types=1);

namespace VendingMachine\Application\InsertCoin;

final readonly class InsertCoinResponse
{
    public function __construct(public int $balanceInCents)
    {
    }
}
