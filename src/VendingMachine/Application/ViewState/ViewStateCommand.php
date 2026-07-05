<?php

declare(strict_types=1);

namespace VendingMachine\Application\ViewState;

/**
 * Asks the machine for a snapshot of its current state.
 *
 * The use case carries no input, but is still modelled as a command for
 * symmetry with the rest of the application layer.
 */
final readonly class ViewStateCommand
{
}
