# Insert Coin — Implementation Plan

## Context

This is the **first** feature of the vending machine (User Story 1). The repo is a fresh
scaffold: Docker + PHP 8.3, Composer (PSR-4 `VendingMachine\` → `src/VendingMachine/`), Pest,
PHPStan level max, and CI are all wired, but `src/` and `tests/` contain only `.gitkeep`
placeholders. So this slice both delivers *Insert Coin* **and** lays the domain / application /
infrastructure foundation the later stories (Return Coins, Buy, View State, Service) will build on.

Goal of the slice: a customer inserts accepted coins **one at a time** (real coin-slot behaviour)
and builds up credit. Accepted denominations: `0.05, 0.10, 0.25, 1.00`. Any other denomination is
rejected and the balance is unchanged.

Decisions confirmed with the user:
- **CLI = interactive REPL** (one long-running process, machine kept in memory across inputs — matches
  the in-memory rule and TASK.md's command-sequence examples).
- **Inserted money is modelled as a collection of `Coin` value objects** (`InsertedMoney` VO), so
  Story 2 (Return Coins) can hand back the actual coins with no rework.

## Architectural boundaries (the layer map we must hold)

- **Domain** — integer cents only. No I/O, no strings, no formatting. Owns *what a valid coin is*
  and *what inserted money is*.
- **Application** — orchestrates one use case. Receives primitives (`amountInCents: int`), talks to
  the domain through a **port**, returns a small result DTO. Never sees stdin or a decimal string.
- **Infrastructure** — the only layer that knows a CLI exists. Parses `"0.25" → 25`, runs the REPL
  loop, formats output, implements the repository port. It *surfaces* the domain's coin-validity
  decision; it never re-implements it.

Adapter-only details (no domain/application impact, chosen as sensible defaults, trivially changed
later): user types a **bare decimal** at the prompt (`0.25`, `1`, `1.00`); after each accepted coin
the REPL echoes the running balance; an invalid coin prints a rejection line and leaves the balance
unchanged; `exit` / `quit` (or EOF) ends the session.

## File tree (new files)

```
src/VendingMachine/domain/
├── Coin.php                                                # VO: valid denominations, in cents
├── InvalidCoinException.php                                # domain exception (unsupported coin)
├── InsertedMoney.php                                       # immutable VO: list<Coin>, total()
├── VendingMachine.php                                      # aggregate root (holds InsertedMoney)
└── VendingMachineRepository.php                            # driven port (interface)

src/VendingMachine/application/InsertCoin/
├── InsertCoinCommand.php                                   # { amountInCents: int }
├── InsertCoinHandler.php                                   # loads → insertCoin → saves
└── InsertCoinResponse.php                                  # { balanceInCents: int }

src/VendingMachine/infrastructure/
├── Persistence/InMemoryVendingMachineRepository.php        # implements the port
└── Cli/VendingMachineConsole.php                           # REPL loop (stream in/out injected)

bin/vending-machine                                         # thin bootstrap: wire + run console

tests/Unit/…            tests/Behaviour/…            tests/Integration/…
```

## Domain (`VendingMachine\Domain\…`)

- **`Coin`** — `enum Coin: int` backed by cents: `FiveCents = 5`, `TenCents = 10`,
  `TwentyFiveCents = 25`, `OneEuro = 100`. This enum is the **single source of truth** for coin
  validity — an invalid denomination is literally unrepresentable.
  - `public static function fromCents(int $cents): self` → `self::tryFrom($cents)` or throw
    `InvalidCoinException`.
  - `public function cents(): int` → returns the backing value.
- **`InvalidCoinException extends \DomainException`** — thrown by `Coin::fromCents` for an
  unsupported amount; carries the offending cents for a good message.
- **`InsertedMoney`** — immutable VO wrapping `list<Coin>`.
  - `public static function none(): self` (empty); `add(Coin $c): self` returns a **new** instance;
    `total(): int` sums `cents()`; `coins(): list<Coin>`.
- **`VendingMachine`** — aggregate root. Private `InsertedMoney`. `public static function new(): self`
  (empty); `insertCoin(Coin $c): void` → `$this->insertedMoney = $this->insertedMoney->add($c)`;
  `insertedBalance(): int` (delegates to `InsertedMoney::total()`). Only ever receives valid `Coin`s.
- **`VendingMachineRepository`** (port, interface): `get(): VendingMachine` (returns the single
  machine), `save(VendingMachine $m): void`. Lives in the domain as the aggregate's persistence
  contract; infra implements it. This is the dependency-inversion seam that keeps the application
  isolated and pays off immediately for the next stories.

## Application (`VendingMachine\Application\InsertCoin\…`)

- **`InsertCoinCommand`** — readonly DTO `{ public int $amountInCents }`.
- **`InsertCoinHandler`** — `__construct(private VendingMachineRepository $machines)`.
  `__invoke(InsertCoinCommand $c): InsertCoinResponse`:
  1. `$coin = Coin::fromCents($c->amountInCents);` (throws `InvalidCoinException` on a bad coin —
     propagates to the adapter, machine untouched)
  2. `$machine = $this->machines->get();`
  3. `$machine->insertCoin($coin);`
  4. `$this->machines->save($machine);`
  5. `return new InsertCoinResponse($machine->insertedBalance());`
- **`InsertCoinResponse`** — readonly DTO `{ public int $balanceInCents }`.

## Infrastructure (`VendingMachine\Infrastructure\…`)

- **`Persistence\InMemoryVendingMachineRepository`** — holds one `?VendingMachine` in a property;
  `get()` lazily creates `VendingMachine::new()` on first call; `save()` stores it. (In-memory,
  per scope.)
- **`Cli\VendingMachineConsole`** — the REPL. Constructor takes input/output stream resources
  (defaulting to `STDIN`/`STDOUT`) and the `InsertCoinHandler`, so integration tests can inject fake
  streams. `run()`:
  - loop `fgets` a line; trim.
  - `exit` / `quit` / EOF → stop.
  - otherwise parse the line to cents with a **string-based** parser (no floats — split on `.`,
    integer part ×100 + up to two decimal digits); non-numeric → print "unrecognised input".
  - call `($handler)(new InsertCoinCommand($cents))`; print `Accepted. Balance: X.XX`.
  - catch `InvalidCoinException` → print `Rejected: <value> is not a valid coin. Balance: X.XX`.
  - The decimal↔cents parsing and the `X.XX` formatting live **only** here.
- **`bin/vending-machine`** — `require vendor/autoload.php`, construct the in-memory repo →
  handler → console, call `run()`.

## Composer autoload change (required, no new dependency)

Current `composer.json` maps only `"VendingMachine\\": "src/VendingMachine/"`. With **lowercase**
layer folders (per CLAUDE.md) and **StudlyCaps** namespaces (PSR-12), PSR-4 breaks on Linux/CI
(case-sensitive). Add explicit per-layer mappings so lowercase dirs resolve to proper namespaces:

```json
"psr-4": {
    "VendingMachine\\Domain\\": "src/VendingMachine/domain/",
    "VendingMachine\\Application\\": "src/VendingMachine/application/",
    "VendingMachine\\Infrastructure\\": "src/VendingMachine/infrastructure/"
}
```

Then `composer dump-autoload`. (Autoload map edit, not a dependency.)

## Tests (Pest, function style)

- **Unit — `tests/Unit/`** (domain, pure):
  - `Coin`: accepts 5/10/25/100; `fromCents(3|50|…)` throws `InvalidCoinException`; `cents()` correct.
  - `InsertedMoney`: `none()` total 0; `add()` accumulates and is immutable (original unchanged);
    `coins()` preserves insertion order; `total()` sums.
  - `VendingMachine`: new machine balance 0; one insert increases balance; multiple inserts
    accumulate.
- **Behaviour — `tests/Behaviour/`** (application through the port, using the in-memory repo or a
  fake): inserting a valid coin updates the persisted balance; several inserts accumulate; inserting
  invalid cents throws `InvalidCoinException` and leaves the persisted balance unchanged; response
  carries the new balance.
- **Integration — `tests/Integration/`**:
  - `InMemoryVendingMachineRepository`: `save` then `get` returns the same state.
  - `VendingMachineConsole`: feed a scripted input stream (e.g. `"0.25\n1\n0.03\nexit\n"`) via fake
    in/out streams; assert output shows the running balances and the rejection line. Exercises
    parsing + wiring end-to-end.

## Verification

1. `docker compose build && docker compose up -d && docker compose exec app composer install`
2. `docker compose exec app composer dump-autoload`
3. `docker compose exec app composer test` → all Pest suites green.
4. `docker compose exec app composer stan` → PHPStan level max clean.
5. Manual REPL smoke test:
   ```
   printf '0.25\n1\n0.03\nexit\n' | docker compose exec -T app php bin/vending-machine
   ```
   Expect: balance 0.25 → 1.25, a rejection for 0.03 with balance still 1.25.

## Suggested commits (branch `feat/insert-coin`, nothing committed without your review)

1. `chore: map hexagonal layers in composer autoload` (composer.json + dump).
2. `feat: model Coin, InsertedMoney and VendingMachine for coin insertion` (domain + unit tests).
3. `feat: add InsertCoin use case with repository port` (application + in-memory repo + behaviour tests).
4. `feat: add CLI REPL entry point for inserting coins` (infra console + bin + integration test).
5. (optional) `docs: add CLI usage note to README`.

## Out of scope (deliberately not built now)

Return Coins, Buy Product, View State, Service, the coin bank, and the product inventory. The
aggregate and repository are introduced minimally now and extended by those later slices.
