<?php

declare(strict_types=1);

namespace VendingMachine\Application\BuyProduct;

final readonly class BuyProductResponse
{
    /**
     * @param list<int> $changeInCents the coins of change returned, largest first
     */
    public function __construct(
        public string $productSelector,
        public array $changeInCents,
        public int $balanceInCents,
    ) {
    }
}
