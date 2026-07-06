<?php

declare(strict_types=1);

namespace VendingMachine\Infrastructure\Persistence;

use PDO;
use PDOStatement;
use RuntimeException;
use VendingMachine\Domain\Coin;
use VendingMachine\Domain\CoinBank;
use VendingMachine\Domain\InsertedMoney;
use VendingMachine\Domain\Inventory;
use VendingMachine\Domain\Product;
use VendingMachine\Domain\ProductCatalogue;
use VendingMachine\Domain\VendingMachine;
use VendingMachine\Domain\VendingMachineRepository;

/**
 * Persists the single vending machine in a SQLite database, so its state
 * survives across processes.
 *
 * The machine is stored as four count-keyed tables (see data/schema.sql). Load
 * reads them and reconstitutes the aggregate through {@see VendingMachine::restore()};
 * save writes the whole state back in one transaction. Because the state is tiny
 * and there is a single machine, save replaces each table wholesale
 * (delete-and-reinsert) rather than diffing rows — trivially correct and easy to
 * reason about.
 */
final class SqliteVendingMachineRepository implements VendingMachineRepository
{
    public function __construct(private readonly PDO $pdo, string $schemaPath)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureSchema($schemaPath);
    }

    public function get(): VendingMachine
    {
        return VendingMachine::restore(
            $this->loadCatalogue(),
            $this->loadInventory(),
            $this->loadCoinBank(),
            $this->loadInsertedMoney(),
        );
    }

    public function save(VendingMachine $machine): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec('DELETE FROM products');
            $this->pdo->exec('DELETE FROM inventory');
            $this->pdo->exec('DELETE FROM coin_bank');
            $this->pdo->exec('DELETE FROM inserted_money');

            $products = $this->pdo->prepare('INSERT INTO products (selector, price_cents) VALUES (:selector, :price)');
            foreach ($machine->catalogue()->all() as $product) {
                $products->execute([':selector' => $product->selector(), ':price' => $product->price()]);
            }

            $this->insertCounts('INSERT INTO inventory (selector, count) VALUES (:key, :count)', $machine->inventory()->counts());
            $this->insertCounts('INSERT INTO coin_bank (cents, count) VALUES (:key, :count)', $machine->coinBank()->counts());
            $this->insertCounts('INSERT INTO inserted_money (cents, count) VALUES (:key, :count)', $machine->insertedMoney()->counts());

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Writes a value object's per-key tally, one row per entry.
     *
     * @param array<array-key, int> $counts key (selector or cents) => count
     */
    private function insertCounts(string $sql, array $counts): void
    {
        $statement = $this->pdo->prepare($sql);

        foreach ($counts as $key => $count) {
            $statement->execute([':key' => $key, ':count' => $count]);
        }
    }

    private function loadCatalogue(): ProductCatalogue
    {
        $catalogue = ProductCatalogue::empty();

        /** @var array{selector: string, price_cents: int|string} $row */
        foreach ($this->query('SELECT selector, price_cents FROM products') as $row) {
            $catalogue = $catalogue->withProduct(Product::new((string) $row['selector'], (int) $row['price_cents']));
        }

        return $catalogue;
    }

    private function loadInventory(): Inventory
    {
        $inventory = Inventory::empty();

        /** @var array{selector: string, count: int|string} $row */
        foreach ($this->query('SELECT selector, count FROM inventory') as $row) {
            $inventory = $inventory->withStock((string) $row['selector'], (int) $row['count']);
        }

        return $inventory;
    }

    private function loadCoinBank(): CoinBank
    {
        $bank = CoinBank::empty();

        /** @var array{cents: int|string, count: int|string} $row */
        foreach ($this->query('SELECT cents, count FROM coin_bank') as $row) {
            $bank = $bank->withCoins(Coin::fromCents((int) $row['cents']), (int) $row['count']);
        }

        return $bank;
    }

    private function loadInsertedMoney(): InsertedMoney
    {
        $counts = [];

        /** @var array{cents: int|string, count: int|string} $row */
        foreach ($this->query('SELECT cents, count FROM inserted_money') as $row) {
            $counts[(int) $row['cents']] = (int) $row['count'];
        }

        return InsertedMoney::fromCounts($counts);
    }

    /**
     * Runs a query. With the connection in exception mode a failure throws, so
     * the false PDO::query() can otherwise return never occurs — this narrows the
     * type for the callers that iterate the result.
     */
    private function query(string $sql): PDOStatement
    {
        $statement = $this->pdo->query($sql);

        assert($statement !== false);

        return $statement;
    }

    private function ensureSchema(string $schemaPath): void
    {
        $schema = @file_get_contents($schemaPath);

        if ($schema === false) {
            throw new RuntimeException(sprintf('Cannot read database schema at "%s".', $schemaPath));
        }

        $this->pdo->exec($schema);
    }
}
