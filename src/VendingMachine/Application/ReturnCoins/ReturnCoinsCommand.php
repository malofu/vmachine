<?php

declare(strict_types=1);

namespace VendingMachine\Application\ReturnCoins;

/**
 * Asks the machine to hand back every coin inserted so far.
 *
 * The use case carries no input, but is still modelled as a command for
 * symmetry with the rest of the application layer.
 */
final readonly class ReturnCoinsCommand
{
}
