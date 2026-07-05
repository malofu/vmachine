<?php

declare(strict_types=1);

namespace VendingMachine\Application\ViewState;

final readonly class ViewStateResponse
{
    /**
     * @param list<ProductState> $products the catalogue, in the machine's own order
     */
    public function __construct(
        public int $balanceInCents,
        public array $products,
    ) {
    }
}
