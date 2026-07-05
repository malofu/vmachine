<?php

declare(strict_types=1);

namespace VendingMachine\Application\ReturnCoins;

final readonly class ReturnCoinsResponse
{
    /**
     * @param list<int> $returnedCoinsInCents the returned coins, in the order
     *                                         they were inserted
     */
    public function __construct(public array $returnedCoinsInCents)
    {
    }
}
