# Service the Machine — Implementation Plan (DRAFT — for discussion)

## Context

Fifth and final feature (User Story 5), on branch `machine-service`. A **service technician** —
a different actor from the customer — refills products and sets the available change so the machine
can keep serving. From `TASK.md`: *"SERVICE — a service person opens the machine and sets the
available change and how many items we have."*

This is the other half of the customer/technician split we designed in Story 4: the customer sees
**availability** (never counts); the technician is the one who sees and sets **exact counts** and the
**coin-bank composition**.

## The decisive constraint: in-memory + single CLI process

`CLAUDE.md` says state is kept **in memory** and the entry point is a **CLI**. The live machine
exists only inside one running process's `InMemoryVendingMachineRepository`. Consequences:

- A **separate service process/executable cannot share the live machine** — it would service its own
  empty instance, which is meaningless. So servicing must happen **inside the same running REPL** as
  the customer, against the same in-memory machine.
- Therefore "the customer can't service" **cannot** be enforced by a separate binary here. It has to
  be a **mode/gate within the one REPL**.

(When persistence arrives later — the same seam that a web UI would need — the technician could
become a genuinely separate process/adapter. Out of scope now.)

## Actor & permission — the open question

Where should "only a worker can service" live?

- **Domain** — modelling roles/authorization as domain concepts (an `Actor`/`Role`, permission
  checks in the aggregate) would be **over-engineering** for this scope (`CLAUDE.md`: don't add
  patterns the task doesn't need). The domain already separates the *operation* cleanly: servicing
  is its own use case touching its own methods; nothing about it is a customer capability.
- **Application** — the use case is simply distinct (`ServiceMachine`), separate from `InsertCoin` /
  `BuyProduct` / `ViewState`. That is the honest "different operation for a different actor" boundary.
- **Infrastructure (recommended home for the "permission")** — *who may invoke* the service use case
  is an adapter concern. In this single-process CLI, model it as a **service session gated by a
  passcode** (simulating the technician's physical key):
  - `service` → prompts for a code; wrong code → refused, stays in customer mode.
  - In service mode, only technician commands work (set stock, set change, view technician state);
    customer commands (`get`, coin insertion) are not offered.
  - `close` / `exit` → leaves service mode, back to the customer face.
  - **This is a simulation of physical access, not authentication.** Real auth is out of scope; the
    gate exists to express "a customer at the machine cannot service it."

**Recommendation:** keep the operation separated in domain+application (no role objects), and put the
access gate in the CLI as a passcode-locked service session. Alternative if you'd rather stay
minimal: an **ungated** `service …` command — simpler, but it does *not* express "the customer can't
do it," which is the thing you said you wanted.

## What servicing sets

Absolute values (not increments), matching *"set … how many items we have"* and *"set the available
change"*:

- **Item counts** per product (e.g. Water → 5).
- **Coin counts** per denomination in the bank (e.g. 0.25 → 20).

Inserted customer money is left untouched (orthogonal to servicing).

## Domain (`VendingMachine\Domain\…`)

- **`VendingMachine::setStock(Product, int): void`** — replaces the count for a product (reassigns
  `Inventory` via the existing immutable `withStock`).
- **`VendingMachine::setChange(Coin, int): void`** — replaces the count for a denomination (reassigns
  `CoinBank` via the existing immutable `withCoins`).
- **`VendingMachine::coinStockOf(Coin): int`** and **`CoinBank::countOf(Coin): int`** — so the
  technician view can show the per-denomination breakdown (mirrors `Inventory::countOf`). `stockOf()`
  and `changeAvailable()` already exist.

Counts are trusted to be non-negative here (as `Coin`/`Product` are trusted valid) — the CLI parser
only accepts non-negative integers, so no new exception is needed.

## Application (`VendingMachine\Application\ServiceMachine\…`)

One cohesive servicing action (a technician "opens the machine and sets everything"):

- **`ServiceMachineCommand`** — `{ array<string,int> $productCounts, array<int,int> $coinCounts }`
  (selector → count, denomination-cents → count). Either map may be partial/empty.
- **`ServiceMachineHandler`** — validates selectors via `Product::fromSelector` and denominations via
  `Coin::fromCents` (unknown → the existing exceptions), applies all sets to the aggregate, saves,
  and returns a technician **`MachineReport`**.
- **`MachineReport`** (technician read model) — `list<ProductStock{selector, priceInCents, count}>`,
  `list<CoinStock{cents, count}>`, `int $changeTotalInCents`. The counts-carrying counterpart to the
  customer's `ProductState`.

*Open sub-decision:* one batch use case (above, atomic, applied on `apply`) **vs** two thin per-set
use cases (`SetStock`, `SetChange`) applied line-by-line. Batch is more faithful to "opens the
machine and sets everything" and stays one use case for the story; per-set is a simpler CLI. Leaning
batch.

## Infrastructure (`VendingMachine\Infrastructure\…`)

- **`Cli\VendingMachineConsole`** — add the passcode-gated **service session**:
  ```
  service                     enter service mode (asks for the code)
    stock <product> <count>   set an item count      (e.g. stock water 5)
    change <coin> <count>     set a coin count        (e.g. change 0.25 20)
    state                     technician view: counts + coin breakdown + total change
    apply                     apply the pending setup and show the result   (if batch)
    close                     leave service mode
  ```
  Customer and technician command sets are disjoint depending on the mode.
- **`bin/vending-machine`** — wire the `ServiceMachineHandler`; define the service passcode here
  (infra config), not in the domain/application.

## Tests (Pest)

- **Unit** — `VendingMachine::setStock`/`setChange` replace counts; `CoinBank::countOf`.
- **Behaviour** — `ServiceMachineHandler`: sets item and coin counts and reports them; rejects an
  unknown product / invalid coin; a serviced machine then vends (e.g. refill a sold-out product and
  it becomes buyable again).
- **Integration** — `VendingMachineConsole`: wrong code is refused; a correct code enters service
  mode; setting stock/change is reflected in the technician `state` and, back in customer mode, in
  availability; customer commands don't work in service mode and vice versa.

## Verification

1. `docker compose exec -T app composer test` → green.
2. `docker compose exec -T app composer stan` → level max clean.
3. Manual REPL smoke test: enter service mode, refill a sold-out product, exit, and buy it.

## Open decisions to settle before coding

1. **Access gate:** passcode-locked service session (recommended) vs ungated `service` command.
2. **Use-case shape:** one batch `ServiceMachine` (recommended) vs two thin per-set use cases.
3. **Command words:** `stock` / `change` (and `service`, `close`) — happy to rename.
4. **Negative counts:** reject at the CLI parser (recommended, no new exception) — confirm.
