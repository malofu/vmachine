<?php

declare(strict_types=1);

use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Application\ReturnCoins\ReturnCoinsHandler;
use VendingMachine\Infrastructure\Cli\VendingMachineConsole;
use VendingMachine\Infrastructure\Persistence\InMemoryVendingMachineRepository;

/**
 * Drives the REPL end-to-end over in-memory streams and returns what it wrote.
 */
function runConsoleWith(string $input): string
{
    $in = fopen('php://memory', 'r+');
    $out = fopen('php://memory', 'r+');
    assert($in !== false && $out !== false);

    fwrite($in, $input);
    rewind($in);

    $repository = new InMemoryVendingMachineRepository();
    $console = new VendingMachineConsole(
        new InsertCoinHandler($repository),
        new ReturnCoinsHandler($repository),
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

    expect($output)->toContain('Insert coins one at a time. Accepted coins: 0.05, 0.10, 0.25, 1.00.');
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
