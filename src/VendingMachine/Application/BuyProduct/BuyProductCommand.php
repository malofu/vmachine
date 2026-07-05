<?php

declare(strict_types=1);

namespace VendingMachine\Application\BuyProduct;

final readonly class BuyProductCommand
{
    public function __construct(public string $productSelector)
    {
    }
}
