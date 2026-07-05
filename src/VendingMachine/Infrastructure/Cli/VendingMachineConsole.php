<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Cli;

use VendingMachine\Application\BuyProduct\BuyProductCommand;
use VendingMachine\Application\BuyProduct\BuyProductHandler;
use VendingMachine\Application\InsertCoin\InsertCoinCommand;
use VendingMachine\Application\InsertCoin\InsertCoinHandler;
use VendingMachine\Application\ReturnCoins\ReturnCoinsCommand;
use VendingMachine\Application\ReturnCoins\ReturnCoinsHandler;
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
 */
final class VendingMachineConsole
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /** Last balance we echoed, so a rejection can show it unchanged. */
    private int $balanceInCents = 0;

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private readonly InsertCoinHandler $insertCoin,
        private readonly ReturnCoinsHandler $returnCoins,
        private readonly BuyProductHandler $buyProduct,
        private readonly ViewStateHandler $viewState,
        $input = STDIN,
        $output = STDOUT,
    ) {
        $this->input = $input;
        $this->output = $output;
    }

    public function run(): void
    {
        $this->greet();

        while (($line = fgets($this->input)) !== false) {
            $entry = trim($line);

            if ($entry === '') {
                continue;
            }

            $command = strtolower($entry);

            if (in_array($command, ['exit', 'quit'], true)) {
                break;
            }

            if (in_array($command, ['return', 'return-coin'], true)) {
                $this->handleReturn();

                continue;
            }

            if (in_array($command, ['state', 'status'], true)) {
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
     * every product, its price and how many are left. A read-only query — it
     * leaves the machine untouched.
     */
    private function handleState(): void
    {
        $response = ($this->viewState)(new ViewStateCommand());
        $this->balanceInCents = $response->balanceInCents;

        $this->writeln(sprintf('Balance: %s', $this->format($response->balanceInCents)));
        $this->writeln('Products:');

        foreach ($response->products as $product) {
            $this->writeln(sprintf(
                '- %s (%s): %d in stock',
                $product->selector,
                $this->format($product->priceInCents),
                $product->stock,
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

    private function greet(): void
    {
        $this->writeln('Vending Machine');
        $this->writeln(sprintf('Insert coins one at a time. Accepted coins: %s.', $this->acceptedCoins()));
        $this->writeln(sprintf("Type 'get <product>' to buy. Products: %s.", $this->availableProducts()));
        $this->writeln("Type 'return' to get your coins back.");
        $this->writeln("Type 'state' to see your balance, the products and their stock.");
        $this->writeln("Type 'exit' to quit.");
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
