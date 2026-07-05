# Buy Product — Implementation Plan

## Context

Third feature of the vending machine (User Story 3), on branch `feat/buy-product` (which
already contains Insert Coin and Return Coins). A customer selects a product and, if the rules
allow, receives the item plus any change owed.

Rules (all must hold; any failure leaves the inserted money untouched so the customer can retry
or ask for it back):
- **Out of stock** → refuse.
- **Insufficient money** (inserted < price) → refuse.
- **Cannot compose exact change** from the coins the machine holds → refuse.

Products and prices (cents): Water `65`, Juice `100`, Soda `150`.

Decisions confirmed with the user:
- **Seed stock and change in the infrastructure layer** (the CLI bootstrap). `VendingMachine::new()`
  stays empty; a `VendingMachine::stocked(Inventory, CoinBank)` factory provisions the demo machine.
  Setting stock/change *at runtime* is the Service technician's job — a later story, out of scope here.
- **No short-changing.** If exact change cannot be composed the sale is refused and the customer
  keeps their credit (and can `return` it). Change is composed with a **count-aware exhaustive
  search**, not greedy: greedy can falsely refuse a sale that smaller coins could satisfy.

## Architectural boundaries (unchanged)

- **Domain** — integer cents only, no I/O. Owns the catalogue, stock, coin bank and the sale rules.
- **Application** — one use case, primitives in (`productSelector: string`), a small DTO out.
- **Infrastructure** — the only layer that parses `get water` and formats `X.XX`; seeds the machine.

## Domain (`VendingMachine\Domain\…`)

- **`Product`** — `enum Product: string` (`Water`/`Juice`/`Soda`), mirroring `Coin`: an unknown
  product is unrepresentable. `price(): int` (cents, single source of truth), `selector(): string`,
  `fromSelector(string): self` (case-insensitive, throws `UnknownProductException`).
- **`UnknownProductException extends \DomainException`** — unknown selector.
- **`Inventory`** — immutable VO, `array<selector, count>`. `empty()`, `withStock(Product, int)`,
  `has()`, `countOf()`, `dispense(Product): self` (guards its invariant → `OutOfStockException`).
- **`OutOfStockException extends \DomainException`**.
- **`CoinBank`** — immutable VO, `array<cents, count>`. `empty()`, `withCoins(Coin, int)`,
  `deposit(list<Coin>): self`, `total()`, `withdraw(int): array{self, list<Coin>}|null`.
  `withdraw` composes exact change via a **count-aware backtracking search** (largest coins first,
  so change comes back in the fewest coins); returns `null` when no combination fits the held counts.
- **`CannotMakeChangeException`**, **`InsufficientMoneyException`** — carry the amounts involved so
  the CLI can phrase a helpful message without re-deriving prices.
- **`Sale`** — result VO: the `Product` to dispense and `list<Coin>` of change.
- **`VendingMachine`** — now holds `InsertedMoney`, `Inventory`, `CoinBank`. New `stocked()` factory
  and `buy(Product): Sale`:
  1. not in stock → `OutOfStockException`.
  2. inserted < price → `InsufficientMoneyException`.
  3. **deposit** the payment into the bank (so the customer's own coins can form their change),
     then `withdraw(inserted - price)`; `null` → `CannotMakeChangeException`.
  4. commit (bank, inventory−1, balance→0) **only** once all checks pass, and return the `Sale`.

## Application (`VendingMachine\Application\BuyProduct\…`)

- **`BuyProductCommand`** — `{ string $productSelector }`.
- **`BuyProductHandler`** — `Product::fromSelector` → `machine->buy` → save → response.
- **`BuyProductResponse`** — `{ string $productSelector, list<int> $changeInCents, int $balanceInCents }`.

## Infrastructure (`VendingMachine\Infrastructure\…`)

- **`Cli\VendingMachineConsole`** — new `get <product>` command (`get water`, `get-soda`, any case).
  Success → `Dispensed: WATER. Change: 0.25, 0.10. Balance: 0.00` (`Change: none` when exact).
  Each rule failure prints a specific line and leaves the balance unchanged. Products (with prices)
  are sourced from the `Product` enum, as accepted coins already are from `Coin`.
- **`bin/vending-machine`** — seeds a `stocked()` machine (each product ×5, a filled coin bank) and
  wires the `BuyProductHandler`.

## Tests (Pest)

- **Unit** — `Product` (prices, case-insensitive selector, unknown throws); `Inventory` (stock,
  immutable dispense, out-of-stock guard); `CoinBank` (total, deposit, exact/zero withdraw, the
  greedy-would-fail case `0.30` from `{0.25×1, 0.10×3}`, impossible → `null`); `VendingMachine.buy`
  (success + change, exact, keeps payment as future change, and each failure leaves money inserted).
- **Behaviour** — `BuyProductHandler`: success DTO + persisted stock decrement; unknown/out-of-stock/
  insufficient/no-change all throw and leave the balance untouched.
- **Integration** — `VendingMachineConsole`: dispense with/without change, and each refusal line.

## Verification

1. `docker compose exec app composer test` → all suites green.
2. `docker compose exec app composer stan` → PHPStan level max clean.
3. Manual REPL smoke test:
   ```
   printf '1\nget water\nexit\n' | docker compose exec -T app php bin/vending-machine
   ```
   Expect: `Dispensed: WATER. Change: 0.25, 0.10. Balance: 0.00`.

## Suggested commits (branch `feat/buy-product`, nothing committed without your review)

1. `feat: model Product, Inventory and CoinBank with change-making` (domain + unit tests).
2. `feat: add BuyProduct use case` (application + behaviour tests).
3. `feat: sell products from the CLI and seed the machine` (console + bin + integration tests).
4. (optional) `docs: add buy product plan`.

## Out of scope (later slices)

View machine state (Story 4) and Service the machine (Story 5). The `withStock` / `withCoins`
seams added here are what Service will drive at runtime.
