<?php

declare(strict_types=1);

namespace VendingMachine\Application\SetChange;

/**
 * The technician sets how many coins of a denomination the machine holds for
 * change, to an absolute count, in a single atomic action.
 */
final readonly class SetChangeCommand
{
    public function __construct(
        public int $cents,
        public int $count,
    ) {
    }
}
