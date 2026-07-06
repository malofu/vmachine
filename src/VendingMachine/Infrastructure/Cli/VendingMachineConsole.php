<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use VendingMachine\Application\BuyProduct\BuyProductCommand;
use VendingMachine\Application\BuyProduct\BuyProductHandler;
use VendingMachine\Application\InsertCoin\InsertCoinCommand;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Application\RemoveProduct\RemoveProductCommand;
use VendingMachine\Application\RemoveProduct\RemoveProductHandler;
use VendingMachine\Application\ReturnCoins\ReturnCoinsCommand;
use VendingMachine\Application\ReturnCoins\ReturnCoinsHandler;
use VendingMachine\Application\ServiceMachine\MachineReport;
use VendingMachine\Application\ServiceMachine\ServiceReportHandler;
use VendingMachine\Application\SetChange\SetChangeCommand;
use VendingMachine\Application\SetChange\SetChangeHandler;
use VendingMachine\Application\SetProductPrice\SetProductPriceCommand;
use VendingMachine\Application\SetProductPrice\SetProductPriceHandler;
use VendingMachine\Application\SetProductStock\SetProductStockCommand;
use VendingMachine\Application\SetProductStock\SetProductStockHandler;
use VendingMachine\Application\ViewState\ProductState;
use VendingMachine\Application\ViewState\ViewStateCommand;
use VendingMachine\Application\ViewState\ViewStateHandler;
use VendingMachine\Domain\CannotMakeChangeException;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\InsufficientMoneyException;
use VendingMachine\Domain\InvalidCoinException;
use VendingMachine\Domain\InvalidProductException;
use VendingMachine\Domain\OutOfStockException;
use VendingMachine\Domain\ProductInStockException;
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

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private readonly InsertCoinHandler $insertCoin,
        private readonly ReturnCoinsHandler $returnCoins,
        private readonly BuyProductHandler $buyProduct,
        private readonly ViewStateHandler $viewState,
        private readonly SetProductPriceHandler $setProductPrice,
        private readonly SetProductStockHandler $setProductStock,
        private readonly RemoveProductHandler $removeProduct,
        private readonly SetChangeHandler $setChange,
        private readonly ServiceReportHandler $serviceReport,
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
        $this->writeln('Service mode.');
        $this->writeln('Commands:');
        $this->writeln('  product <selector> <price>  add or reprice a product (e.g. product water 0.65)');
        $this->writeln('  remove <selector>           remove a product (e.g. remove water)');
        $this->writeln('  stock <product> <count>     set a product\'s stock (e.g. stock water 10)');
        $this->writeln('  change <coin> <count>       set a coin\'s change count (e.g. change 0.25 20)');
        $this->writeln('  state                       show stock and change counts');
        $this->writeln('  exit                        leave service mode');
        $this->writeln('');
        $this->showServiceState();
    }

    /**
     * Routes a line typed while in service mode. Only technician commands are
     * honoured here; customer commands are not.
     */
    private function handleServiceEntry(string $entry, string $command): void
    {
        if (in_array($command, ['exit', 'quit'], true)) {
            $this->exitServiceMode();

            return;
        }

        if ($command === 'state') {
            $this->showServiceState();

            return;
        }

        if (preg_match('/^product\s+(\w+)\s+(\S+)$/i', $entry, $matches) === 1) {
            $this->handleSetProductPrice($matches[1], $matches[2]);

            return;
        }

        if (preg_match('/^remove\s+(\w+)$/i', $entry, $matches) === 1) {
            $this->handleRemoveProduct($matches[1]);

            return;
        }

        if (preg_match('/^stock\s+(\w+)\s+(\d+)$/i', $entry, $matches) === 1) {
            $this->handleSetStock($matches[1], (int) $matches[2]);

            return;
        }

        if (preg_match('/^change\s+(\S+)\s+(\d+)$/i', $entry, $matches) === 1) {
            $this->handleSetChange($matches[1], (int) $matches[2]);

            return;
        }

        $this->writeln(sprintf(
            'Unrecognised service command: "%s". Use product <selector> <price>, remove <selector>, '
            . 'stock <product> <count>, change <coin> <count>, state or exit.',
            $entry,
        ));
    }

    /**
     * Defines a product or reprices it, then shows the resulting state. Each
     * service action is applied on its own, atomically; a rejected one changes
     * nothing and leaves nothing behind to retry.
     */
    private function handleSetProductPrice(string $selector, string $price): void
    {
        $cents = $this->parseCents($price);

        if ($cents === null) {
            $this->writeln(sprintf('Unrecognised price: "%s".', $price));

            return;
        }

        try {
            ($this->setProductPrice)(new SetProductPriceCommand($selector, $cents));
        } catch (InvalidProductException $e) {
            $this->writeln(sprintf('Rejected: %s', $e->getMessage()));

            return;
        }

        $this->showServiceState();
    }

    private function handleSetStock(string $selector, int $count): void
    {
        try {
            ($this->setProductStock)(new SetProductStockCommand($selector, $count));
        } catch (UnknownProductException $e) {
            $this->writeln(sprintf('Rejected: %s', $e->getMessage()));

            return;
        }

        $this->showServiceState();
    }

    private function handleRemoveProduct(string $selector): void
    {
        try {
            ($this->removeProduct)(new RemoveProductCommand($selector));
        } catch (UnknownProductException | ProductInStockException $e) {
            $this->writeln(sprintf('Rejected: %s', $e->getMessage()));

            return;
        }

        $this->showServiceState();
    }

    private function handleSetChange(string $coin, int $count): void
    {
        $cents = $this->parseCents($coin);

        if ($cents === null) {
            $this->writeln(sprintf('Unrecognised amount: "%s".', $coin));

            return;
        }

        try {
            ($this->setChange)(new SetChangeCommand($cents, $count));
        } catch (InvalidCoinException $e) {
            $this->writeln(sprintf('Rejected: %s', $e->getMessage()));

            return;
        }

        $this->showServiceState();
    }

    /**
     * Leaves service mode and shows the customer face again. Nothing is staged,
     * so there is nothing to discard.
     */
    private function exitServiceMode(): void
    {
        $this->serviceMode = false;
        $this->writeln('Service closed.');
        $this->handleState();
    }

    /**
     * The technician view: exact per-product and per-denomination counts. Also
     * shown after each service action, so the technician sees its effect.
     */
    private function showServiceState(): void
    {
        $this->printReport(($this->serviceReport)());
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
        $this->writeln('  service        technician service mode');
        $this->writeln('  exit           quit');
        $this->writeln('');
    }

    /**
     * The products the machine can sell, formatted for display. Sourced from the
     * state query so the live catalogue stays the single authority on what the
     * machine sells.
     */
    private function availableProducts(): string
    {
        $response = ($this->viewState)(new ViewStateCommand());

        return implode(', ', array_map(
            fn (ProductState $product): string => sprintf(
                '%s (%s)',
                $product->selector,
                $this->format($product->priceInCents),
            ),
            $response->products,
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
