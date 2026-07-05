<?php

declare(strict_types=1);

namespace VendingMachine\Application\BuyProduct;

use VendingMachine\Domain\Coin;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Buys a product for the money inserted so far.
 *
 * The selector arrives as a plain string; turning it into a Product is where an
 * unknown selection is rejected (UnknownProductException). The domain enforces
 * the sale rules (stock, sufficient money, exact change) and only a successful
 * purchase is persisted.
 */
final readonly class BuyProductHandler
{
    public function __construct(private VendingMachineRepository $machines)
    {
    }

    public function __invoke(BuyProductCommand $command): BuyProductResponse
    {
        $product = Product::fromSelector($command->productSelector);

        $machine = $this->machines->get();
        $sale = $machine->buy($product);
        $this->machines->save($machine);

        return new BuyProductResponse(
            $sale->product()->selector(),
            array_map(static fn (Coin $coin): int => $coin->cents(), $sale->change()),
            $machine->insertedBalance(),
        );
    }
}
