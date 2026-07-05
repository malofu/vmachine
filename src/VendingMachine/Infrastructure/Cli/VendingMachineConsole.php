<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use VendingMachine\Application\BuyProduct\BuyProductCommand;
use VendingMachine\Application\BuyProduct\BuyProductHandler;
use VendingMachine\Application\InsertCoin\InsertCoinCommand;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Application\ReturnCoins\ReturnCoinsCommand;
use VendingMachine\Application\ReturnCoins\ReturnCoinsHandler;
use VendingMachine\Application\ServiceMachine\MachineReport;
use VendingMachine\Application\ServiceMachine\ServiceMachineCommand;
use VendingMachine\Application\ServiceMachine\ServiceMachineHandler;
use VendingMachine\Application\ViewState\ViewStateCommand;
use VendingMachine\Application\ViewState\ViewStateHandler;
use VendingMachine\Domain\CannotMakeChangeException;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\InsufficientMoneyException;
use VendingMachine\Domain\InvalidCoinException;
use VendingMachine\Domain\OutOfStockException;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\UnknownProductException;

/**
 * Interactive REPL: the user types one coin per line (e.g. "0.25", "1") and the
 * machine echoes the running balance, just like feeding a real coin slot.
 *
 * This is the only place that knows about strings and I/O. Parsing a decimal
 * into cents and formatting cents back into a "X.XX" string live here; the
 * application and domain only ever deal in integer cents.
 *
 * The machine has two audiences. Customers insert coins, buy and view state. A
 * service technician enters a passcode-gated service mode to refill stock and
 * change — a simulation of the physical key that keeps a customer out, not real
 * authentication.
 */
final class VendingMachineConsole
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /** Last balance we echoed, so a rejection can show it unchanged. */
    private int $balanceInCents = 0;

    /** Whether the technician has unlocked service mode. */
    private bool $serviceMode = false;

    /** @var array<string, int> pending item counts staged in service mode (selector => count) */
    private array $pendingProductCounts = [];

    /** @var array<int, int> pending coin counts staged in service mode (cents => count) */
    private array $pendingCoinCounts = [];

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private readonly InsertCoinHandler $insertCoin,
        private readonly ReturnCoinsHandler $returnCoins,
        private readonly BuyProductHandler $buyProduct,
        private readonly ViewStateHandler $viewState,
        private readonly ServiceMachineHandler $serviceMachine,
        private readonly string $serviceCode,
        $input = STDIN,
        $output = STDOUT,
    ) {
        $this->input = $input;
        $this->output = $output;
    }

    public function run(): void
    {
        $this->greet();
        $this->handleState();

        while (($line = fgets($this->input)) !== false) {
            $entry = trim($line);

            if ($entry === '') {
                continue;
            }

            $command = strtolower($entry);

            if ($this->serviceMode) {
                $this->handleServiceEntry($entry, $command);

                continue;
            }

            if (in_array($command, ['exit', 'quit'], true)) {
                break;
            }

            if (in_array($command, ['return', 'return-coin'], true)) {
                $this->handleReturn();

                continue;
            }

            if ($command === 'service') {
                $this->enterServiceMode();

                continue;
            }

            if ($command === 'state') {
                $this->handleState();

                continue;
            }

            if (preg_match('/^get[\s\-]+(\w+)$/i', $entry, $matches) === 1) {
                $this->handleBuy($matches[1]);

                continue;
            }

            $this->handle($entry);
        }
    }

    private function handle(string $entry): void
    {
        $cents = $this->parseCents($entry);

        if ($cents === null) {
            $this->writeln(sprintf(
                'Unrecognised input: "%s". Accepted coins: %s.',
                $entry,
                $this->acceptedCoins(),
            ));

            return;
        }

        try {
            $response = ($this->insertCoin)(new InsertCoinCommand($cents));
            $this->balanceInCents = $response->balanceInCents;
            $this->writeln(sprintf('Accepted. Balance: %s', $this->format($this->balanceInCents)));
        } catch (InvalidCoinException) {
            $this->writeln(sprintf(
                'Rejected: %s is not a valid coin. Accepted coins: %s. Balance: %s',
                $entry,
                $this->acceptedCoins(),
                $this->format($this->balanceInCents),
            ));
        }
    }

    /**
     * Hands the customer back everything they inserted and resets the balance.
     */
    private function handleReturn(): void
    {
        $response = ($this->returnCoins)(new ReturnCoinsCommand());
        $this->balanceInCents = 0;

        if ($response->returnedCoinsInCents === []) {
            $this->writeln(sprintf('No coins to return. Balance: %s', $this->format(0)));

            return;
        }

        $coins = implode(', ', array_map(
            fn (int $cents): string => $this->format($cents),
            $response->returnedCoinsInCents,
        ));

        $this->writeln(sprintf('Returned: %s. Balance: %s', $coins, $this->format(0)));
    }

    /**
     * Shows the customer where they stand: the money inserted so far and, for
     * every product, its price and whether it can be bought right now. A
     * read-only query — it leaves the machine untouched.
     */
    private function handleState(): void
    {
        $response = ($this->viewState)(new ViewStateCommand());
        $this->balanceInCents = $response->balanceInCents;

        $this->writeln(sprintf('Balance: %s', $this->format($response->balanceInCents)));
        $this->writeln('Products:');

        foreach ($response->products as $product) {
            $this->writeln(sprintf(
                '- %s (%s): %s',
                $product->selector,
                $this->format($product->priceInCents),
                $product->available ? 'available' : 'sold out',
            ));
        }
    }

    /**
     * Attempts to buy a product. On success the item is dispensed with any
     * change; on any rule violation the balance is left untouched so the
     * customer can add more coins or ask for their money back.
     */
    private function handleBuy(string $selector): void
    {
        try {
            $response = ($this->buyProduct)(new BuyProductCommand($selector));
            $this->balanceInCents = $response->balanceInCents;
            $this->writeln(sprintf(
                'Dispensed: %s. Change: %s. Balance: %s',
                $response->productSelector,
                $this->formatChange($response->changeInCents),
                $this->format($this->balanceInCents),
            ));
        } catch (UnknownProductException) {
            $this->writeln(sprintf(
                'Unknown product: "%s". Available: %s. Balance: %s',
                $selector,
                $this->availableProducts(),
                $this->format($this->balanceInCents),
            ));
        } catch (OutOfStockException) {
            $this->writeln(sprintf(
                'Out of stock: %s. Balance: %s',
                strtoupper($selector),
                $this->format($this->balanceInCents),
            ));
        } catch (InsufficientMoneyException $e) {
            $this->writeln(sprintf(
                'Insufficient balance for %s: needs %s. Balance: %s',
                strtoupper($selector),
                $this->format($e->priceInCents()),
                $this->format($this->balanceInCents),
            ));
        } catch (CannotMakeChangeException) {
            $this->writeln(sprintf(
                'Cannot give exact change for %s. Insert the exact amount or type \'return\'. Balance: %s',
                strtoupper($selector),
                $this->format($this->balanceInCents),
            ));
        }
    }

    /**
     * Unlocks service mode if the next line matches the service code — the
     * adapter's stand-in for the technician's physical key. A customer without
     * the code stays in customer mode.
     */
    private function enterServiceMode(): void
    {
        $this->writeln('Service — enter code:');

        $line = fgets($this->input);
        $code = $line === false ? '' : trim($line);

        if (!hash_equals($this->serviceCode, $code)) {
            $this->writeln('Access denied.');

            return;
        }

        $this->serviceMode = true;
        $this->pendingProductCounts = [];
        $this->pendingCoinCounts = [];
        $this->writeln('Service mode. Commands: stock <product> <count>, change <coin> <count>, state, apply, close.');
        $this->showServiceState();
    }

    /**
     * Routes a line typed while in service mode. Only technician commands are
     * honoured here; customer commands are not.
     */
    private function handleServiceEntry(string $entry, string $command): void
    {
        if ($command === 'close') {
            $this->closeServiceMode();

            return;
        }

        if ($command === 'state') {
            $this->showServiceState();

            return;
        }

        if ($command === 'apply') {
            $this->applyService();

            return;
        }

        if (preg_match('/^stock\s+(\w+)\s+(\d+)$/i', $entry, $matches) === 1) {
            $this->pendingProductCounts[strtoupper($matches[1])] = (int) $matches[2];
            $this->writeln(sprintf('Staged: %s -> %d. Type \'apply\' to commit.', strtoupper($matches[1]), (int) $matches[2]));

            return;
        }

        if (preg_match('/^change\s+(\S+)\s+(\d+)$/i', $entry, $matches) === 1) {
            $cents = $this->parseCents($matches[1]);

            if ($cents === null) {
                $this->writeln(sprintf('Unrecognised amount: "%s".', $matches[1]));

                return;
            }

            $this->pendingCoinCounts[$cents] = (int) $matches[2];
            $this->writeln(sprintf('Staged: %s -> %d. Type \'apply\' to commit.', $this->format($cents), (int) $matches[2]));

            return;
        }

        $this->writeln(sprintf(
            'Unrecognised service command: "%s". Use stock <product> <count>, change <coin> <count>, state, apply or close.',
            $entry,
        ));
    }

    /**
     * Commits the staged setup as one atomic servicing action. On an unknown
     * product or invalid coin nothing is changed and the draft is kept so the
     * technician can fix it.
     */
    private function applyService(): void
    {
        try {
            $report = ($this->serviceMachine)(new ServiceMachineCommand(
                $this->pendingProductCounts,
                $this->pendingCoinCounts,
            ));
        } catch (UnknownProductException | InvalidCoinException $e) {
            $this->writeln(sprintf('Cannot apply: %s Nothing changed.', $e->getMessage()));

            return;
        }

        $this->pendingProductCounts = [];
        $this->pendingCoinCounts = [];
        $this->writeln('Applied.');
        $this->printReport($report);
    }

    /**
     * Leaves service mode, discarding any staged-but-unapplied setup, and shows
     * the customer face again.
     */
    private function closeServiceMode(): void
    {
        if ($this->pendingProductCounts !== [] || $this->pendingCoinCounts !== []) {
            $this->writeln('Discarded un-applied changes.');
        }

        $this->serviceMode = false;
        $this->pendingProductCounts = [];
        $this->pendingCoinCounts = [];
        $this->writeln('Service closed.');
        $this->handleState();
    }

    /**
     * The technician view: exact per-product and per-denomination counts. Sent
     * as an empty service command, which applies nothing and returns the
     * current report.
     */
    private function showServiceState(): void
    {
        $this->printReport(($this->serviceMachine)(new ServiceMachineCommand()));
    }

    private function printReport(MachineReport $report): void
    {
        $this->writeln('Stock:');

        foreach ($report->products as $product) {
            $this->writeln(sprintf(
                '- %s (%s): %d',
                $product->selector,
                $this->format($product->priceInCents),
                $product->count,
            ));
        }

        $this->writeln('Change:');

        foreach ($report->coins as $coin) {
            $this->writeln(sprintf('- %s: %d', $this->format($coin->cents), $coin->count));
        }

        $this->writeln(sprintf('Total change: %s', $this->format($report->changeTotalInCents)));
    }

    /**
     * Orients the customer on how to drive the machine — accepted coins and the
     * available commands. What the machine currently holds (products, prices,
     * availability) is the job of 'state', which is shown right after this.
     */
    private function greet(): void
    {
        $this->writeln('Vending Machine');
        $this->writeln(sprintf('Accepted coins: %s.', $this->acceptedCoins()));
        $this->writeln('Commands:');
        $this->writeln('  <coin>         insert a coin (e.g. 0.25)');
        $this->writeln('  get <product>  buy a product (e.g. get water)');
        $this->writeln('  return         return your inserted coins');
        $this->writeln('  state          show balance, products, prices and availability');
        $this->writeln('  exit           quit');
        $this->writeln('');
    }

    /**
     * The products the machine can sell, formatted for display. Sourced from the
     * Product enum so the domain stays the single authority on the catalogue.
     */
    private function availableProducts(): string
    {
        return implode(', ', array_map(
            fn (Product $product): string => sprintf('%s (%s)', $product->selector(), $this->format($product->price())),
            Product::cases(),
        ));
    }

    /**
     * @param list<int> $changeInCents
     */
    private function formatChange(array $changeInCents): string
    {
        if ($changeInCents === []) {
            return 'none';
        }

        return implode(', ', array_map(
            fn (int $cents): string => $this->format($cents),
            $changeInCents,
        ));
    }

    /**
     * The accepted denominations, formatted for display. Sourced from the Coin
     * enum so the domain stays the single authority on what a valid coin is.
     */
    private function acceptedCoins(): string
    {
        return implode(', ', array_map(
            fn (Coin $coin): string => $this->format($coin->cents()),
            Coin::cases(),
        ));
    }

    /**
     * Parses a bare decimal amount into cents without ever using floats.
     * Returns null when the input is not a well-formed amount.
     */
    private function parseCents(string $entry): ?int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $entry, $matches) !== 1) {
            return null;
        }

        $euros = (int) $matches[1];
        $cents = isset($matches[2]) ? (int) str_pad($matches[2], 2, '0') : 0;

        return $euros * 100 + $cents;
    }

    private function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function writeln(string $message): void
    {
        fwrite($this->output, $message . PHP_EOL);
    }
}
