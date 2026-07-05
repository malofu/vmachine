<?php

declare(strict_types=1);

namespace VendingMachine\Application\ServiceMachine;

/**
 * A single servicing action: the technician opens the machine and sets item
 * counts and coin counts to absolute values. Either map may be partial or empty
 * (an empty command applies nothing and simply reports the current state).
 */
final readonly class ServiceMachineCommand
{
    /**
     * @param array<string, int> $productCounts product selector => absolute count
     * @param array<int, int>    $coinCounts    denomination in cents => absolute count
     */
    public function __construct(
        public array $productCounts = [],
        public array $coinCounts = [],
    ) {
    }
}
