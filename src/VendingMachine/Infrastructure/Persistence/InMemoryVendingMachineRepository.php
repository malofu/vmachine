<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Persistence;

use VendingMachine\Domain\VendingMachine;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Keeps the single machine in memory for the lifetime of the process, which is
 * all the CLI REPL needs.
 */
final class InMemoryVendingMachineRepository implements VendingMachineRepository
{
    private ?VendingMachine $machine = null;

    public function get(): VendingMachine
    {
        return $this->machine ??= VendingMachine::new();
    }

    public function save(VendingMachine $machine): void
    {
        $this->machine = $machine;
    }
}
