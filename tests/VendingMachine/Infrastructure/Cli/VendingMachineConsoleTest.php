<?php

declare(strict_types=1);

use VendingMachine\Application\BuyProduct\BuyProductHandler;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Application\RemoveProduct\RemoveProductHandler;
use VendingMachine\Application\ReturnCoins\ReturnCoinsHandler;
use VendingMachine\Application\ServiceMachine\ServiceReportHandler;
use VendingMachine\Application\SetChange\SetChangeHandler;
use VendingMachine\Application\SetProductPrice\SetProductPriceHandler;
use VendingMachine\Application\SetProductStock\SetProductStockHandler;
use VendingMachine\Application\ViewState\ViewStateHandler;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Infrastructure\Cli\VendingMachineConsole;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

/**
 * The default three-product catalogue (Water, Juice, Soda), in the order the
 * customer sees them.
 */
function fullCatalogue(): ProductCatalogue
{
    return ProductCatalogue::empty()
        ->withProduct(Product::new('WATER', 65))
        ->withProduct(Product::new('JUICE', 100))
        ->withProduct(Product::new('SODA', 150));
}

/**
 * A single-product catalogue, for scenarios that only exercise Water.
 */
function waterCatalogue(): ProductCatalogue
{
    return ProductCatalogue::empty()->withProduct(Product::new('WATER', 65));
}

/**
 * A machine with plenty of stock and change, so a scenario only has to worry
 * about the coins it feeds in. Tests that need scarcity pass their own machine.
 */
function stockedMachine(): VendingMachine
{
    return VendingMachine::stocked(
        fullCatalogue(),
        Inventory::empty()
            ->withStock('WATER', 2)
            ->withStock('JUICE', 1)
            ->withStock('SODA', 1),
        CoinBank::empty()
            ->withCoins(Coin::OneEuro, 5)
            ->withCoins(Coin::TwentyFiveCents, 10)
            ->withCoins(Coin::TenCents, 10)
            ->withCoins(Coin::FiveCents, 10),
    );
}

/**
 * Drives the REPL end-to-end over in-memory streams and returns what it wrote.
 */
function runConsoleWith(string $input, ?VendingMachine $machine = null, string $serviceCode = '1234'): string
{
    $in = fopen('php://memory', 'r+');
    $out = fopen('php://memory', 'r+');
    assert($in !== false && $out !== false);

    fwrite($in, $input);
    rewind($in);

    $repository = new InMemoryVendingMachineRepository();
    $repository->save($machine ?? stockedMachine());
    $console = new VendingMachineConsole(
        new InsertCoinHandler($repository),
        new ReturnCoinsHandler($repository),
        new BuyProductHandler($repository),
        new ViewStateHandler($repository),
        new SetProductPriceHandler($repository),
        new SetProductStockHandler($repository),
        new RemoveProductHandler($repository),
        new SetChangeHandler($repository),
        new ServiceReportHandler($repository),
        $serviceCode,
        $in,
        $out,
    );
    $console->run();

    rewind($out);
    $output = stream_get_contents($out);
    assert($output !== false);

    return $output;
}

it('greets with the accepted coins on start', function () {
    $output = runConsoleWith("exit\n");

    expect($output)->toContain('Accepted coins: 0.05, 0.10, 0.25, 1.00.');
});

it('offers the state command on start', function () {
    $output = runConsoleWith("exit\n");

    expect($output)->toContain('state')
        ->and($output)->toContain('show balance, products, prices and availability');
});

it('shows the machine state on start so the customer sees the catalogue', function () {
    $output = runConsoleWith("exit\n");

    expect($output)->toContain('Balance: 0.00')
        ->and($output)->toContain('- WATER (0.65): available')
        ->and($output)->toContain('- JUICE (1.00): available')
        ->and($output)->toContain('- SODA (1.50): available');
});

it('echoes the running balance as coins are inserted', function () {
    $output = runConsoleWith("0.25\n1\nexit\n");

    expect($output)->toContain('Accepted. Balance: 0.25')
        ->and($output)->toContain('Accepted. Balance: 1.25');
});

it('rejects an invalid coin, reminds of the accepted coins and keeps the balance unchanged', function () {
    $output = runConsoleWith("0.25\n0.03\nexit\n");

    expect($output)->toContain(
        'Rejected: 0.03 is not a valid coin. Accepted coins: 0.05, 0.10, 0.25, 1.00. Balance: 0.25',
    );
});

it('reports unrecognised input, reminds of the accepted coins and does not affect the balance', function () {
    $output = runConsoleWith("abc\n0.25\nexit\n");

    expect($output)->toContain('Unrecognised input: "abc". Accepted coins: 0.05, 0.10, 0.25, 1.00.')
        ->and($output)->toContain('Accepted. Balance: 0.25');
});

it('returns all inserted coins and resets the balance', function () {
    $output = runConsoleWith("0.10\n0.10\nreturn\nexit\n");

    expect($output)->toContain('Returned: 0.10, 0.10. Balance: 0.00');
});

it('lets the customer keep inserting after a return', function () {
    $output = runConsoleWith("0.25\nreturn\n0.10\nexit\n");

    expect($output)->toContain('Returned: 0.25. Balance: 0.00')
        ->and($output)->toContain('Accepted. Balance: 0.10');
});

it('reports there is nothing to return when no coins were inserted', function () {
    $output = runConsoleWith("return\nexit\n");

    expect($output)->toContain('No coins to return. Balance: 0.00');
});

it('dispenses a product with change and resets the balance', function () {
    $output = runConsoleWith("1\nget water\nexit\n");

    expect($output)->toContain('Dispensed: WATER. Change: 0.25, 0.10. Balance: 0.00');
});

it('dispenses without change when the exact amount is inserted', function () {
    $output = runConsoleWith("1\n0.25\n0.25\nget soda\nexit\n");

    expect($output)->toContain('Dispensed: SODA. Change: none. Balance: 0.00');
});

it('refuses to sell when the balance is insufficient and keeps the balance', function () {
    $output = runConsoleWith("0.25\nget water\nexit\n");

    expect($output)->toContain('Insufficient balance for WATER: needs 0.65. Balance: 0.25');
});

it('refuses to sell an out-of-stock product and keeps the balance', function () {
    $machine = VendingMachine::stocked(
        waterCatalogue(),
        Inventory::empty()->withStock('WATER', 0),
        CoinBank::empty()->withCoins(Coin::TenCents, 10),
    );

    $output = runConsoleWith("1\nget water\nexit\n", $machine);

    expect($output)->toContain('Out of stock: WATER. Balance: 1.00');
});

it('refuses to sell when it cannot compose exact change and keeps the balance', function () {
    $machine = VendingMachine::stocked(
        waterCatalogue(),
        Inventory::empty()->withStock('WATER', 1),
        CoinBank::empty(),
    );

    $output = runConsoleWith("1\nget water\nexit\n", $machine);

    expect($output)->toContain("Cannot give exact change for WATER. Insert the exact amount or type 'return'. Balance: 1.00");
});

it('reports an unknown product and lists the ones it sells', function () {
    $output = runConsoleWith("get cola\nexit\n");

    expect($output)->toContain('Unknown product: "cola". Available: WATER (0.65), JUICE (1.00), SODA (1.50).');
});

it('shows the balance, products, prices and availability on request', function () {
    $machine = VendingMachine::stocked(
        fullCatalogue(),
        Inventory::empty()
            ->withStock('WATER', 2)
            ->withStock('JUICE', 1)
            ->withStock('SODA', 0),
        CoinBank::empty(),
    );

    $output = runConsoleWith("0.25\nstate\nexit\n", $machine);

    expect($output)->toContain('Balance: 0.25')
        ->and($output)->toContain('Products:')
        ->and($output)->toContain('- WATER (0.65): available')
        ->and($output)->toContain('- JUICE (1.00): available')
        ->and($output)->toContain('- SODA (1.50): sold out');
});

it('reflects a purchase in the state it reports afterwards', function () {
    $machine = VendingMachine::stocked(
        waterCatalogue(),
        Inventory::empty()->withStock('WATER', 1),
        CoinBank::empty()
            ->withCoins(Coin::TwentyFiveCents, 1)
            ->withCoins(Coin::TenCents, 1),
    );

    $output = runConsoleWith("1\nget water\nstate\nexit\n", $machine);

    expect($output)->toContain('Dispensed: WATER')
        ->and($output)->toContain('- WATER (0.65): sold out');
});

it('refuses service mode when the code is wrong and keeps the customer out', function () {
    $output = runConsoleWith("service\n0000\nexit\n");

    expect($output)->toContain('Access denied.')
        ->and($output)->not->toContain('Service mode.');
});

it('does not honour service commands without unlocking service mode', function () {
    $machine = VendingMachine::stocked(
        waterCatalogue(),
        Inventory::empty()->withStock('WATER', 0),
        CoinBank::empty(),
    );

    $output = runConsoleWith("stock water 5\nstate\nexit\n", $machine);

    // 'stock water 5' is not a customer command; it must not refill anything.
    expect($output)->toContain('Unrecognised input: "stock water 5"')
        ->and($output)->toContain('- WATER (0.65): sold out');
});

it('enters service mode with the right code and shows the technician view', function () {
    $output = runConsoleWith("service\n1234\nexit\nexit\n");

    expect($output)->toContain('Service mode.')
        ->and($output)->toContain('Stock:')
        ->and($output)->toContain('- WATER (0.65): 2')
        ->and($output)->toContain('Change:')
        ->and($output)->toContain('- 1.00: 5')
        ->and($output)->toContain('Total change: 9.00');
});

it('refills stock and change immediately, reflected in the technician view', function () {
    $machine = VendingMachine::stocked(
        waterCatalogue(),
        Inventory::empty()->withStock('WATER', 0),
        CoinBank::empty(),
    );

    $output = runConsoleWith(
        "service\n1234\nstock water 5\nchange 0.25 8\nexit\nexit\n",
        $machine,
    );

    expect($output)->toContain('- WATER (0.65): 5')
        ->and($output)->toContain('- 0.25: 8')
        ->and($output)->toContain('Total change: 2.00');
});

it('makes a refilled product buyable again for the customer', function () {
    $machine = VendingMachine::stocked(
        waterCatalogue(),
        Inventory::empty()->withStock('WATER', 0),
        CoinBank::empty(),
    );

    // Sold out at first; the technician refills Water and loads exact-change coins,
    // then the customer buys it.
    $output = runConsoleWith(
        "service\n1234\nstock water 1\nchange 0.25 1\nchange 0.10 1\nexit\n1\nget water\nexit\n",
        $machine,
    );

    expect($output)->toContain('Service closed.')
        ->and($output)->toContain('- WATER (0.65): available')
        ->and($output)->toContain('Dispensed: WATER. Change: 0.25, 0.10. Balance: 0.00');
});

it('rejects a bad service command and changes nothing', function () {
    $machine = VendingMachine::stocked(
        waterCatalogue(),
        Inventory::empty()->withStock('WATER', 0),
        CoinBank::empty(),
    );

    $output = runConsoleWith(
        "service\n1234\nstock cola 5\nstate\nexit\nexit\n",
        $machine,
    );

    expect($output)->toContain('Rejected:')
        ->and($output)->toContain('- WATER (0.65): 0');
});

it('is not blocked by a rejected command: a later valid one still applies', function () {
    $machine = VendingMachine::stocked(
        waterCatalogue(),
        Inventory::empty()->withStock('WATER', 0),
        CoinBank::empty(),
    );

    // 'stock cola 5' is rejected (COLA is not sold); the following valid
    // 'stock water 3' must still take effect — nothing lingers to poison it.
    $output = runConsoleWith(
        "service\n1234\nstock cola 5\nstock water 3\nexit\nexit\n",
        $machine,
    );

    expect($output)->toContain('Rejected:')
        ->and($output)->toContain('- WATER (0.65): 3');
});

it('lets the technician define a new product that a customer can then buy', function () {
    // Starts from the default three-product machine and adds COLA at 1.25.
    $output = runConsoleWith(
        "service\n1234\nproduct cola 1.25\nstock cola 3\nexit\n1\n0.25\nget cola\nexit\n",
    );

    expect($output)->toContain('- COLA (1.25): 3')
        ->and($output)->toContain('- COLA (1.25): available')
        ->and($output)->toContain('Dispensed: COLA. Change: none. Balance: 0.00');
});

it('refuses to remove a stocked product, then removes it once the slot is emptied', function () {
    $output = runConsoleWith(
        "service\n1234\nproduct cola 1.25\nstock cola 2\n"
        . "remove cola\n"
        . "stock cola 0\nremove cola\nexit\n"
        . "get cola\nexit\n",
    );

    expect($output)->toContain('still has stock')
        ->and($output)->toContain('Unknown product: "cola"');
});
