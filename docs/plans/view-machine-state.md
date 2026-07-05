# View Machine State — Implementation Plan

## Context

Fourth feature of the vending machine (User Story 4), on branch `feat/machine-state` (which already
contains Insert Coin, Return Coins and Buy Product). A customer asks to see where they stand:

- **inserted balance**,
- every product with its **price** and remaining **stock**.

This is a **read-only query** — it never mutates the machine.

Scope note: available change is machine state the domain already tracks, but Story 4's acceptance
criteria list only *balance, products, prices and stock*. Surfacing change to the customer is left
out here; it belongs to the Service technician's view (Story 5).

## Architectural boundaries (unchanged)

- **Domain** — integer cents only, no I/O. Already exposes everything the query needs
  (`VendingMachine::insertedBalance()`, `VendingMachine::stockOf(Product)`, `Product::price()`), so
  **no domain change is required**.
- **Application** — one use case: no input, a small DTO out. Reads the aggregate through the port.
- **Infrastructure** — the only layer that formats `X.XX` and prints the state; adds the `state`
  command to the REPL and wires the handler.

## Domain

No changes. The aggregate's existing query methods and the `Product` enum (the single source of
truth for the catalogue and its prices) already cover the story.

## Application (`VendingMachine\Application\ViewState\…`)

- **`ViewStateCommand`** — empty readonly command (symmetry with `ReturnCoinsCommand`).
- **`ProductState`** — readonly DTO `{ string $selector, int $priceInCents, int $stock }`: one
  catalogue line as the customer sees it.
- **`ViewStateResponse`** — `{ int $balanceInCents, list<ProductState> $products }`.
- **`ViewStateHandler`** — loads the machine and maps `Product::cases()` to a `ProductState` each
  (`price()` + `stockOf()`), plus `insertedBalance()`. **No save** — viewing does not mutate.

## Infrastructure (`VendingMachine\Infrastructure\…`)

- **`Cli\VendingMachineConsole`** — new `state` / `status` command. Prints:
  ```
  Balance: 0.25
  Products:
  - WATER (0.65): 5 in stock
  - JUICE (1.00): 5 in stock
  - SODA (1.50): 5 in stock
  ```
  The greeting gains a line advertising the command. Formatting stays here, as elsewhere.
- **`bin/vending-machine`** — wires the new `ViewStateHandler` into the console.

## Tests (Pest)

- **Behaviour** — `ViewStateHandler`: reports the balance and every product with its price and
  stock; zero balance when nothing inserted; leaves the machine untouched (query, not command).
- **Integration** — `VendingMachineConsole`: greeting advertises `state`; `state` prints balance,
  products and stock; state reflects a prior purchase (stock decremented, balance cleared).

## Verification

1. `docker compose exec -T app composer test` → all suites green.
2. `docker compose exec -T app composer stan` → PHPStan level max clean.
3. Manual REPL smoke test:
   ```
   printf '0.25\nstate\n1\nget water\nstate\nexit\n' | docker compose exec -T app php bin/vending-machine
   ```
   Expect the state block before and after the sale, with Water's stock decremented.

## Suggested commits (branch `feat/machine-state`, nothing committed without your review)

1. `feat: add ViewState use case` (application + behaviour tests).
2. `feat: show machine state from the CLI` (console + bin + integration tests).
3. (optional) `docs: add view machine state plan`.

## Out of scope (later slice)

Service the machine (Story 5): setting stock and change at runtime. The `withStock` / `withCoins`
seams it will drive already exist.
