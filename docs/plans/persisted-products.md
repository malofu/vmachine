# Persisted Machine State — Implementation Plan

## Context

Branch `feat/persisted-products`. Today the machine's entire state lives in `InMemoryVendingMachineRepository`
— one field, alive only for the process. Restart the CLI and the catalogue, stock, change and inserted
money are gone (re-seeded from the `bin` bootstrap). This story makes that state **survive across
processes**, directly answering `TASK.md`'s framing of "a foundation that other engineers will build
upon" and "imagine this code will be part of a larger system".

The architecture was built for exactly this. The domain already defines the port:

```php
interface VendingMachineRepository {
    public function get(): VendingMachine;
    public function save(VendingMachine $machine): void;
}
```

Every handler already does `get() → mutate → save()`. So persistence is **not a rewrite — it is a
second adapter behind an existing seam.** The in-memory implementation stays (it is what keeps the test
suite fast and side-effect-free); we add a SQLite one and select between them at the bootstrap.

> **Note — this supersedes a CLAUDE.md rule.** CLAUDE.md currently says *"State is kept in memory for
> this scope."* This feature deliberately changes that. Updating CLAUDE.md and the README is part of the
> work (see [Documentation](#documentation)).

## Decisions (confirmed with the user)

1. **SQLite, with normalized tables** — not a JSON blob. Reviewable in `git`, honest integration tests
   against a real database, and the natural place to demonstrate a transactional `save`. `pdo_sqlite`
   and `sqlite3` are already bundled in the `php:8.3-cli-alpine` image (verified — no Dockerfile change).
2. **The in-memory repository stays and remains the default.** Selected by env var so tests and the
   default run stay in memory (fast, no files on disk).
3. **Inserted money persists as counts, not as an ordered list.** The `InsertedMoney` value object keeps
   coins in insertion order, but that order is *only* observable as the print order when coins are
   returned — it changes no amount and no coin. So there is **no positional `inserted_coins(position, …)`
   table**; inserted money is stored as `denomination → count`, symmetric with the coin bank, and rebuilt
   from those counts on load. We keep faithful "resume with the same balance and coins"; we drop a
   sequence nobody can observe.
4. **The whole aggregate is one persistence boundary.** Catalogue, inventory, coin bank and inserted
   money are loaded and saved together through the single existing port — no separate `ProductRepository`.
   This matches the aggregate boundary and keeps `save` a single transaction.
5. **DDL and default data live in versioned `.sql` files under `data/`, not in PHP.** `data/schema.sql`
   holds the `CREATE TABLE` statements; `data/seed.sql` holds the default provisioning (Water/Juice/Soda,
   their stock, the coin float). Both are executed by the app but authored as plain SQL — reviewable in a
   diff, runnable by hand (`sqlite3 data/machine.sqlite < data/schema.sql`), and the honest artifact a DBA
   would expect. The runtime SQLite file also lives in `data/` (git-ignored). See
   [`data/` layout](#data-on-disk).

   *Trade-off surfaced:* the default catalogue is now expressed **twice** — as `seed.sql` (used by the
   SQLite adapter) and as the PHP objects the `bin` bootstrap builds for the **in-memory** adapter, which
   cannot run SQL. They must be kept in step. This is an accepted, small duplication (each store seeds in
   its own idiom); if you'd rather have one source of truth, say so and I'll seed both from the PHP
   defaults and keep `seed.sql` only as documentation.

## The domain seam (small, no behaviour change)

To be snapshotted and rebuilt, the aggregate must *expose* its state and be *reconstructible*. These are
pure read accessors and one named constructor — no rule changes, no new behaviour.

### `VendingMachine`

- **`restore(ProductCatalogue $catalogue, Inventory $inventory, CoinBank $coinBank, InsertedMoney $insertedMoney): self`**
  — reconstitution constructor. Unlike `stocked()` (which forces `InsertedMoney::none()`), `restore`
  carries the inserted money through, so a reloaded machine resumes exactly.
- Read accessors for the repository to snapshot, mirroring the existing **`catalogue(): ProductCatalogue`**:
  - **`inventory(): Inventory`**
  - **`coinBank(): CoinBank`**
  - **`insertedMoney(): InsertedMoney`**

  *(Alternative considered: bespoke `snapshot()` DTO instead of exposing the VOs. Rejected — the VOs are
  immutable and `catalogue()` already sets this precedent; a parallel DTO is a layer the task doesn't need.)*

### `Inventory`, `CoinBank`, `InsertedMoney` — a `counts()` reader each

- **`Inventory::counts(): array<string,int>`** — `selector → count`.
- **`CoinBank::counts(): array<int,int>`** — `cents → count`.
- **`InsertedMoney::counts(): array<int,int>`** — `cents → count` (grouped from the ordered list) and
  **`InsertedMoney::fromCounts(array<int,int> $counts): self`** — rebuild from counts (expands each
  `cents → count` back into coins; order is not preserved, by decision #3).

`ProductCatalogue::all(): list<Product>` and `Product::selector()/price()` already exist — no change there.

## Infrastructure (`VendingMachine\Infrastructure\Persistence\…`)

### `SqliteVendingMachineRepository implements VendingMachineRepository`

- **Constructor takes a `PDO` and the path to `schema.sql`** (both provided by `bin`; tests pass the real
  `data/schema.sql`). On construction it runs the schema file so `get()`/`save()` can assume the tables
  are present. Passing the path in — rather than hard-coding a `__DIR__`-relative hop from
  `Infrastructure/Persistence` up to `data/` — keeps the adapter decoupled from the repo's folder layout
  and trivially testable.
- **`data/schema.sql`** (single machine → four state tables, no anchor/version row needed):
  ```sql
  CREATE TABLE IF NOT EXISTS products       (selector TEXT PRIMARY KEY, price_cents INTEGER NOT NULL);
  CREATE TABLE IF NOT EXISTS inventory      (selector TEXT PRIMARY KEY, count INTEGER NOT NULL);
  CREATE TABLE IF NOT EXISTS coin_bank      (cents INTEGER PRIMARY KEY, count INTEGER NOT NULL);
  CREATE TABLE IF NOT EXISTS inserted_money (cents INTEGER PRIMARY KEY, count INTEGER NOT NULL);
  ```
  `inserted_money` mirrors `coin_bank` — counts only, no position column (decision #3).
- **`get(): VendingMachine`**
  - Reads the four state tables.
  - Rebuilds the VOs: `ProductCatalogue` via `Product::new(selector, price)`; `Inventory` via
    `withStock`; `CoinBank` via `Coin::fromCents(cents)` + `withCoins`; `InsertedMoney::fromCounts(...)`.
  - Returns `VendingMachine::restore(...)`. A fresh DB → empty catalogue/stock/change and zero balance
    (which the bootstrap detects and seeds — see below).
- **`save(VendingMachine $machine): void`** — one transaction: `DELETE FROM` then bulk `INSERT` each of
  `products`, `inventory`, `coin_bank`, `inserted_money` from the aggregate's `counts()` snapshots.
  Delete-and-reinsert (not per-row diffing) — the state is tiny and it keeps `save` trivially correct.
  Any failure rolls the whole thing back, so a save is all-or-nothing.

### `InMemoryVendingMachineRepository`

- Unchanged.

## Bootstrap & configuration

### `bin/vending-machine` — pick the adapter, seed only an empty store

- Read `PERSISTENCE` (`memory` | `sqlite`, default `memory`) and `DB_PATH` (default `data/machine.sqlite`)
  from the existing `.env` loader.
- `memory` → `new InMemoryVendingMachineRepository()`, seeded from the PHP defaults (today's behaviour).
- `sqlite` → ensure the `DB_PATH` directory exists, build `new PDO('sqlite:' . $dbPath)` with
  `ERRMODE_EXCEPTION`, `new SqliteVendingMachineRepository($pdo, 'data/schema.sql')`.
- **Seed only when the store is empty**, each store in its own idiom:
  ```php
  $machine = $repository->get();
  if ($machine->catalogue()->all() === []) {
      // sqlite → run data/seed.sql through the PDO;  memory → save() the PHP default machine
  }
  ```
  An empty catalogue is the "never provisioned" signal — no new port method needed. A store that already
  has products is loaded verbatim, so a restart resumes the real state. For SQLite the defaults come from
  `data/seed.sql`; for in-memory they come from the PHP objects `bin` already builds (the duplication
  flagged in decision #5).

### `data/seed.sql` — default provisioning

- Plain `INSERT`s for the current defaults, so a fresh SQLite database boots ready to vend:
  ```sql
  INSERT INTO products  (selector, price_cents) VALUES ('WATER', 65), ('JUICE', 100), ('SODA', 150);
  INSERT INTO inventory (selector, count)       VALUES ('WATER', 5),  ('JUICE', 5),   ('SODA', 5);
  INSERT INTO coin_bank (cents, count)          VALUES (100, 10), (25, 20), (10, 20), (5, 20);
  -- inserted_money starts empty: a fresh machine holds no customer coins
  ```

### `.env.example`

- Document `PERSISTENCE=memory` and `DB_PATH=data/machine.sqlite` alongside the existing `SERVICE_CODE`.

### `composer.json`

- Add `"ext-pdo_sqlite": "*"` to `require` — **documentation of an already-present platform extension**,
  not a new package to install. (Flagging per CLAUDE.md's "ask before adding a dependency": this pulls in
  nothing; it only makes the existing requirement explicit. Say if you'd rather leave it out.)

### `data/` on disk

- New top-level `data/` folder holding both the SQL sources and the runtime database:
  ```
  data/
    schema.sql        tracked — the DDL, run on repository construction
    seed.sql          tracked — default provisioning for a fresh SQLite store
    machine.sqlite    git-ignored — the live database, created on first sqlite run
    .gitignore        ignores *.sqlite (and -wal/-shm/-journal), keeps the .sql files
  ```
- `data/.gitignore` ignores the database artifacts but **keeps `schema.sql` / `seed.sql` tracked** so the
  schema and defaults are reviewable and versioned.

## Tests (Pest)

- **Integration — the heart of this story** (`Infrastructure/Persistence/SqliteVendingMachineRepositoryTest`):
  - Use a **temp-file DB per test** (not `:memory:`) — the whole point is a cross-connection round-trip,
    which `:memory:` cannot express. Create in a temp path, `unlink` in teardown. Construct the repository
    with the real `data/schema.sql` (resolved from the project root) so the actual shipped DDL is exercised.
  - *Round-trip:* build a machine (catalogue + stock + coin bank + some inserted coins), `save` via one
    repository, then `get` via a **new repository instance on the same file** → identical catalogue,
    prices, stock, coin counts and inserted balance. Proves state genuinely crosses processes.
  - *Fresh DB:* a new file auto-creates the schema from `data/schema.sql` and `get` returns an empty,
    zero-balance machine.
  - *Overwrite:* saving twice to the same file leaves only the second state (delete-and-reinsert, no
    stale rows).
  - *`seed.sql` loads:* running `data/seed.sql` into a schema-created DB yields, via `get()`, the expected
    default catalogue/stock/change — guards the seed SQL against drift.
- **Unit — domain seam** (extend existing files):
  - `InventoryTest` / `CoinBankTest`: `counts()` returns the expected map.
  - `InsertedMoneyTest`: `counts()` groups by denomination; `fromCounts()` round-trips the balance
    (and, explicitly, does *not* promise coin order — documents decision #3).
  - `VendingMachineTest`: `restore(...)` rebuilds an equivalent machine, inserted balance included;
    `inventory()/coinBank()/insertedMoney()` expose the live state.
- **Unchanged:** `InMemoryVendingMachineRepositoryTest`, and every handler/console test (they run on the
  in-memory default).

## Documentation

- **`CLAUDE.md`** — replace the "State is kept in memory for this scope" line with the two-adapter reality
  (in-memory default, SQLite opt-in via `PERSISTENCE`).
- **`README.md`** — document `PERSISTENCE` / `DB_PATH`, how to run in SQLite mode, and note that state
  then survives restarts.

## Verification

1. `docker compose exec -T app composer test` → all suites green.
2. `docker compose exec -T app composer stan` → PHPStan clean.
3. `docker compose exec -T app composer cs` → PSR-12 clean.
4. Manual persistence smoke test (SQLite mode — state must survive a full process restart):
   ```
   # Run 1: buy something / restock, then exit
   PERSISTENCE=sqlite printf 'service\n<code>\nstock water 2\napply\nexit\n1\nget water\nexit\n' \
     | docker compose exec -T -e PERSISTENCE=sqlite app php bin/vending-machine

   # Run 2: a brand-new process — state from run 1 is still there
   printf 'state\nexit\n' \
     | docker compose exec -T -e PERSISTENCE=sqlite app php bin/vending-machine
   ```
   Expect run 2 to show the stock left by run 1, not the re-seeded defaults.
5. Confirm the default (no `PERSISTENCE`) still runs fully in memory and writes no files.

## Suggested commits (branch `feat/persisted-products`, nothing committed without your review)

1. `feat: expose machine state for persistence` — `VendingMachine::restore` + `inventory()/coinBank()/
   insertedMoney()`; `counts()` on `Inventory`/`CoinBank`/`InsertedMoney` + `InsertedMoney::fromCounts`;
   domain unit tests.
2. `feat: sqlite vending machine repository` — `SqliteVendingMachineRepository`, `data/schema.sql`,
   `data/.gitignore`, integration tests.
3. `feat: select persistence from the environment` — `bin` adapter selection + seed-if-empty,
   `data/seed.sql`, `.env.example`, `composer.json` ext line.
4. `docs: persisted state plan + README/CLAUDE updates`.

## Out of scope

- **Dynamic coins** (the roadmap's skipped middle step). Denominations stay a fixed enum for now; the
  `coin_bank` table is keyed by `cents`, so making coins dynamic later needs **no schema change**.
- Any datastore other than SQLite (Postgres/MySQL) — the port makes it a future adapter, not this task.
- Real authentication or multi-machine support (still a single machine, `id = 1`).
