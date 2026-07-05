# Service the Machine — Implementation Plan

## Context

Fifth and final feature (User Story 5), on branch `machine-service`. A **service technician** — a
different actor from the customer — refills products and sets the available change so the machine can
keep serving. From `TASK.md`: *"SERVICE — a service person opens the machine and sets the available
change and how many items we have."*

This is the other half of the customer/technician split from Story 4: the customer sees
**availability** (never counts); the technician is the one who sees and sets **exact item counts** and
the **coin-bank composition**.

## Decisions (confirmed with the user)

1. **Access is gated by a passcode** — servicing lives behind a service session unlocked by a code
   (simulating the technician's physical key). A customer at the machine cannot service it.
2. **One batch use case** — `ServiceMachine` applies the whole desired setup (item counts + coin
   counts) atomically, matching *"opens the machine and sets everything."*
3. **Command words** — `service` / `stock` / `change` / `state` / `apply` / `close`.
4. **Non-negative counts** — enforced at the CLI parser (only non-negative integers accepted), so the
   domain needs no new exception.

## The decisive constraint: in-memory + single CLI process

`CLAUDE.md` keeps state **in memory** with a **CLI** entry point, so the live machine exists only
inside one running process. A separate service binary could not share it (it would service its own
empty instance), so servicing must happen **inside the same running REPL** as the customer. Hence the
"only a worker can service" rule is expressed as a **passcode-gated mode within the one REPL**, not a
separate program.

> This is a **simulation of physical access, not authentication.** Real auth is out of scope. The
> actor separation is real in the *design* (distinct use case; technician-only commands); only the
> *deployment* is constrained by the in-memory rule. When persistence is added later — the same seam a
> web UI needs — the technician could become a genuinely separate process/adapter.

## Where the "permission" lives

- **Domain** — no roles/authorization objects (that would be over-engineering for this scope). The
  domain separates the *operation*: servicing is its own set of methods, not a customer capability.
- **Application** — the separation is simply a distinct `ServiceMachine` use case.
- **Infrastructure** — *who may invoke it* is the adapter's concern: the passcode-gated service
  session. The passcode itself is infra config (defined in `bin/vending-machine`).

## What servicing sets

Absolute values (not increments): **item counts** per product (e.g. Water → 5) and **coin counts**
per denomination (e.g. 0.25 → 20). Inserted customer money is left untouched (orthogonal to
servicing).

## Domain (`VendingMachine\Domain\…`)

- **`VendingMachine::setStock(Product, int): void`** — replaces a product's count (reassigns
  `Inventory` via the existing immutable `withStock`).
- **`VendingMachine::setChange(Coin, int): void`** — replaces a denomination's count (reassigns
  `CoinBank` via the existing immutable `withCoins`).
- **`CoinBank::countOf(Coin): int`** and **`VendingMachine::coinStockOf(Coin): int`** — expose the
  per-denomination breakdown for the technician view (mirrors `Inventory::countOf`). `stockOf()`,
  `changeAvailable()` and `isAvailable()` already exist.

Counts are trusted non-negative here (as `Coin`/`Product` are trusted valid) — the CLI parser is the
guard, so no new domain exception.

## Application (`VendingMachine\Application\ServiceMachine\…`)

- **`ServiceMachineCommand`** — `{ array<string,int> $productCounts, array<int,int> $coinCounts }`
  (product selector → count; denomination-cents → count). Either map may be partial or empty.
- **`ServiceMachineHandler`** — `__invoke(ServiceMachineCommand): MachineReport`:
  1. **Validate the whole command first** — resolve every selector via `Product::fromSelector` and
     every denomination via `Coin::fromCents` (unknown → the existing `UnknownProductException` /
     `InvalidCoinException`). Nothing is applied until all entries resolve, so a bad entry leaves the
     machine untouched (atomic).
  2. Apply each `setStock` / `setChange` to the aggregate.
  3. `save`, then return a `MachineReport`.
  - An **empty command is a valid no-op** that just returns the current report — this is how the CLI
    renders the technician `state` view without a second use case.
- **`MachineReport`** (technician read model) — `list<ProductStock> $products`,
  `list<CoinStock> $coins`, `int $changeTotalInCents`. The counts-carrying counterpart to the
  customer's `ProductState`.
- **`ProductStock`** — `{ string $selector, int $priceInCents, int $count }`.
- **`CoinStock`** — `{ int $cents, int $count }`.

The report iterates `Product::cases()` and `Coin::cases()` so the domain stays the single authority
on the catalogue and the denominations.

## Infrastructure (`VendingMachine\Infrastructure\…`)

- **`Cli\VendingMachineConsole`** — gains a **service session** and a `bool` mode flag plus a small
  pending-setup draft (`array<string,int>` products, `array<int,int>` coins):
  - In **customer mode**, a new command `service` prompts for the code (read from the next input
    line). Wrong code → `Access denied.`, stay in customer mode. Correct code → enter service mode
    and show the technician `state`.
  - In **service mode**, only technician commands are recognised (customer commands are not offered):
    ```
    stock <product> <count>   accumulate an item count into the pending setup   (e.g. stock water 5)
    change <coin> <count>     accumulate a coin count into the pending setup      (e.g. change 0.25 20)
    state                     technician view: per-product counts, per-coin counts, total change
    apply                     commit the pending setup atomically, show the report, clear the draft
    close                     leave service mode (a non-empty un-applied draft is discarded, with a note)
    ```
  - `stock` / `change` only parse and stage input (non-negative integer count; coin as a decimal →
    cents). Whether a selector/denomination is *valid* is decided by the handler on `apply`, which
    reports `UnknownProductException` / `InvalidCoinException` as a message and keeps the draft.
  - The passcode and `X.XX` formatting stay in this layer, as elsewhere.
- **`bin/vending-machine`** — wire the `ServiceMachineHandler` and define the service passcode here
  (infra config), passed into the console.

## Tests (Pest)

- **Unit** — `VendingMachine::setStock` / `setChange` replace counts; `CoinBank::countOf` reports a
  denomination's count.
- **Behaviour** — `ServiceMachineHandler`: sets item and coin counts and reports them; an empty
  command returns the current report unchanged; an unknown product or invalid coin throws and leaves
  the machine untouched (atomicity); after refilling a sold-out product it becomes available and
  buyable.
- **Integration** — `VendingMachineConsole`: a wrong code is refused and customer stays out; a correct
  code enters service mode; `stock`/`change` + `apply` are reflected in the technician `state` and,
  back in customer mode, in customer availability; a customer command is not honoured in service mode
  (and `service`/technician commands are not honoured in customer mode).

## Verification

1. `docker compose exec -T app composer test` → all suites green.
2. `docker compose exec -T app composer stan` → PHPStan level max clean.
3. Manual REPL smoke test:
   ```
   printf 'service\n<code>\nstock water 2\nchange 0.25 10\napply\nstate\nclose\nget water\nexit\n' \
     | docker compose exec -T app php bin/vending-machine
   ```
   Expect: enter service mode, apply refills the machine, the technician `state` shows the new
   counts, and back in customer mode Water is buyable.

## Suggested commits (branch `machine-service`, nothing committed without your review)

1. `feat: let the machine set stock and change and report coin counts` (domain + unit tests).
2. `feat: add ServiceMachine use case` (application + behaviour tests).
3. `feat: add passcode-gated service session to the CLI` (console + bin + integration tests).
4. (optional) `docs: add service machine plan`.

## Out of scope

Real authentication/authorization (the passcode is a simulation of physical access), and persisting
state across processes (which is what would let the technician be a separate adapter). Both are
deliberate, per `CLAUDE.md`'s in-memory + CLI scope.
