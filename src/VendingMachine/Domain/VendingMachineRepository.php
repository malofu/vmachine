<?php

declare(strict_types=1);

namespace VendingMachine\Domain;

/**
 * Persistence contract for the single vending machine. The domain defines it;
 * the infrastructure layer provides the concrete (in-memory) implementation.
 */
interface VendingMachineRepository
{
    public function get(): VendingMachine;

    public function save(VendingMachine $machine): void;
}
