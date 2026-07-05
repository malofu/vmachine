# View Machine State — Implementation Plan

## Context

Fourth feature of the vending machine (User Story 4), on branch `feat/machine-state` (which already
contains Insert Coin, Return Coins and Buy Product). A customer asks to see where they stand:

- **inserted balance**,
- every product with its **price** and **availability** (`available` / `sold out`).

This is a **read-only query** — it never mutates the machine.

Two deliberate scope decisions (confirmed with the user):

- **Availability, not counts.** A customer sees whether a product can be bought, not the exact
  remaining count. Exact stock and the coin-bank composition are the *service technician's* concern
  (Story 5) — a different actor with a different read model. The customer read model (`ProductState`)
  therefore carries `available: bool` and never the number, so the count cannot leak through any
  adapter. The literal Story 4 wording says "stock"; we read that, for a customer, as availability.
- **Available change is not shown** to the customer for the same reason — it belongs to Story 5.

The greeting and `state` are also **unified by responsibility**: the greeting only explains *how to
drive* the machine (accepted coins + commands); `state` is the single source of truth for *what the
machine holds* (balance, products, prices, availability). To avoid a first-time user not knowing the
catalogue, `state` is **shown once automatically at startup** (the machine's "face"), then on demand.

## Architectural boundaries (unchanged)

- **Domain** — integer cents only, no I/O. Already exposes everything the query needs
  (`VendingMachine::insertedBalance()`, `VendingMachine::stockOf(Product)`, `Product::price()`), so
  **no domain change is required**.
- **Application** — one use case: no input, a small DTO out. Reads the aggregate through the port.
- **Infrastructure** — the only layer that formats `X.XX` and prints the state; adds the `state`
  command to the REPL and wires the handler.

## Domain

- **`VendingMachine::isAvailable(Product): bool`** — a customer-facing query delegating to
  `Inventory::has()`. Keeps the "can I buy this?" concept in the domain and keeps counts out of the
  customer path. `stockOf()` stays for the aggregate's own use and the future technician view.

Otherwise unchanged: the `Product` enum remains the single source of truth for the catalogue and
its prices.

## Application (`VendingMachine\Application\ViewState\…`)

- **`ViewStateCommand`** — empty readonly command (symmetry with `ReturnCoinsCommand`).
- **`ProductState`** — readonly DTO `{ string $selector, int $priceInCents, bool $available }`: one
  catalogue line as the customer sees it — availability, deliberately not the count.
- **`ViewStateResponse`** — `{ int $balanceInCents, list<ProductState> $products }`.
- **`ViewStateHandler`** — loads the machine and maps `Product::cases()` to a `ProductState` each
  (`price()` + `isAvailable()`), plus `insertedBalance()`. **No save** — viewing does not mutate.

## Infrastructure (`VendingMachine\Infrastructure\…`)

- **`Cli\VendingMachineConsole`** — new `state` command (single word — no `status` alias). Prints:
  ```
  Balance: 0.25
  Products:
  - WATER (0.65): available
  - JUICE (1.00): available
  - SODA (1.50): sold out
  ```
  The greeting is slimmed to accepted coins + the command list (it no longer lists the catalogue),
  and `state` is rendered once right after the greeting on startup. Formatting stays here, as
  elsewhere.
- **`bin/vending-machine`** — wires the new `ViewStateHandler` into the console.

## Tests (Pest)

- **Unit** — `VendingMachine::isAvailable()` is true with stock and false once the last unit sells.
- **Behaviour** — `ViewStateHandler`: reports the balance and every product with its price and
  availability; zero balance when nothing inserted; leaves the machine untouched (query, not command).
- **Integration** — `VendingMachineConsole`: greeting advertises `state`; the catalogue is shown on
  startup; `state` prints balance, prices and `available`/`sold out`; a purchase that empties a slot
  flips it to `sold out`.

## Verification

1. `docker compose exec -T app composer test` → all suites green.
2. `docker compose exec -T app composer stan` → PHPStan level max clean.
3. Manual REPL smoke test:
   ```
   printf '0.25\nstate\n1\nget water\nstate\nexit\n' | docker compose exec -T app php bin/vending-machine
   ```
   Expect the state block on startup and after the sale, with availability reflecting the purchase.

## Suggested commits (branch `feat/machine-state`, nothing committed without your review)

1. `feat: expose customer-facing product availability on the machine` (domain + unit test).
2. `feat: add ViewState use case` (application + behaviour tests).
3. `feat: show machine state from the CLI and unify the greeting` (console + bin + integration tests).
4. (optional) `docs: add view machine state plan`.

## Out of scope (later slice)

Service the machine (Story 5): setting stock and change at runtime. The `withStock` / `withCoins`
seams it will drive already exist.
