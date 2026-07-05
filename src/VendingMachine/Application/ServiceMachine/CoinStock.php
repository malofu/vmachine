<?php

declare(strict_types=1);

namespace VendingMachine\Application\ServiceMachine;

/**
 * One denomination line of the technician's view: how many coins of this value
 * the machine holds for change.
 */
final readonly class CoinStock
{
    public function __construct(
        public int $cents,
        public int $count,
    ) {
    }
}
