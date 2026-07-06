# Dynamic Products — Implementation Plan

## Context

Branch `feat/dynamic-products`. Today the catalogue is a closed `Product` enum: the set of products
is fixed in code. This story turns products into **runtime state the technician manages** — define,
reprice and remove — directly answering `TASK.md`'s *"What if we need to add new products?"*.

This is deliberately scoped to **products only**. Coins stay a closed enum: denominations are the
machine's hardware, not configuration. (A machine deployed in another country would need different
coins/bills, but that variability is not interesting for this exercise — making *products* dynamic is
enough to demonstrate the concept. Dynamic coins are a possible later step.)

Product management is a **technician** capability. It lives behind the same passcode-gated service
mode as restocking and setting change. A customer can only insert coins, buy, return and view state —
never touch the catalogue.

## Decisions (confirmed with the user)

1. **Coins stay a fixed enum.** Only products go dynamic. The asymmetry is intentional and
   defensible: products are business data, denominations are hardware.
2. **The aggregate resolves selectors.** `VendingMachine::buy(string $selector)` — turning a selector
   into a product is a sale rule, so it belongs in the aggregate, not the handler. `Product::fromSelector`
   goes away.
3. **Catalogue and Inventory stay separate concepts.** The *catalogue* is "what the machine sells and
   for how much" (selector → price). The *inventory* is "how many of each remain" (selector → count).
   Two concerns, kept apart — this also sets up the later products-persistence step cleanly.
4. **The catalogue lives inside the `VendingMachine` aggregate** for now — loaded and saved through the
   existing repository port, one transactional boundary. No separate `ProductRepository` (that layer
   isn't needed at this scope).
5. **Add, reprice and remove are all supported.** Adding and repricing are the same operation
   (`setProduct`). **Removal is forbidden while stock > 0** — the technician must empty the slot first.
   This keeps a clean invariant ("you don't discard product that's physically in the machine") and a
   good rule to defend.

## Key design point: what "closed enum" gave us, and where each guarantee moves

The enum bought four things for free; each has an explicit new home once products are data.

| Enum gave us | New home |
| --- | --- |
| Validity by construction | `Product::new(selector, priceInCents)` guards invariants → new `InvalidProductException` |
| Price always present | Price stays a field on the `Product` value object (int cents, > 0) |
| `Product::cases()` = the catalogue | `ProductCatalogue` VO, reached via `machine->catalogue()` |
| `fromSelector()` validates a selection | `ProductCatalogue::get()` / `VendingMachine::buy(selector)` throw the existing `UnknownProductException` |

## Domain (`VendingMachine\Domain\…`)

### `Product` — enum → final immutable value object

- Private constructor; named constructor **`Product::new(string $selector, int $priceInCents): self`**.
- Invariants, guarded in `new`:
  - selector normalised as `strtoupper(trim($selector))`; empty after trim → `InvalidProductException`.
  - `priceInCents > 0` → `InvalidProductException`.
- Keeps the current read API unchanged: **`selector(): string`**, **`price(): int`** — so
  `Sale`, `OutOfStockException::forProduct`, `InsufficientMoneyException::forProduct` and the read
  models keep working against the same methods.
- `fromSelector()` and the `price()` `match` are removed (resolution and pricing are now data).

### `InvalidProductException` (new, extends `DomainException`)

- `Product::emptySelector()` and `Product::nonPositivePrice(int $priceInCents)` named constructors.

### `ProductCatalogue` (new immutable value object)

- Backed by `array<string, Product>` keyed by selector.
- `empty(): self`
- `withProduct(Product $product): self` — adds or replaces (**reprice** is just re-adding a product
  with the same selector).
- `withoutProduct(string $selector): self` — removes; unknown selector → `UnknownProductException`.
  (This VO only knows the catalogue; the *stock > 0* guard lives in the aggregate, which is the only
  thing that can see inventory.)
- `get(string $selector): Product` — throws `UnknownProductException` (the resolution the enum's
  `fromSelector` used to do).
- `has(string $selector): bool`
- `all(): list<Product>` — for the read models and the console menu.

### `UnknownProductException`

- Unchanged (already `forSelector(string)`); its home shifts from the enum to the catalogue/aggregate.

### `ProductInStockException` (new, extends `DomainException`)

- `forSelector(string $selector)` — thrown by `VendingMachine::removeProduct` when stock remains.

### `Inventory` — keyed by selector *strings* (decouple from `Product`)

Inventory only ever used `$product->selector()` internally. Now that products are dynamic and the
aggregate resolves them, Inventory operates on plain selectors — "how many of X remain" needs no
`Product`. This is the clean expression of decision #3 and matches the future inventory table.

- `withStock(string $selector, int $count): self`
- `has(string $selector): bool`
- `countOf(string $selector): int`
- `dispense(string $selector): self` — defensive guard throws `OutOfStockException::forSelector(...)`.
- `without(string $selector): self` (new) — drops a slot, so `removeProduct` leaves no orphan entry.

  *(Lower-churn alternative kept for the interview: leave Inventory taking `Product`. Rejected — it
  would keep inventory coupled to the catalogue for a pure counting concern.)*

- `OutOfStockException` gains `forSelector(string)`; `forProduct(Product)` stays for the aggregate's
  own pre-check (where it holds the resolved product).

### `VendingMachine` — gains the catalogue as a fourth field

- Constructor / factories carry `ProductCatalogue`:
  - `new(): self` → `new self(none, Inventory::empty(), CoinBank::empty(), ProductCatalogue::empty())`.
  - `stocked(ProductCatalogue $catalogue, Inventory $inventory, CoinBank $coinBank): self`.
- **`buy(string $selector): Sale`** — first `$product = $this->catalogue->get($selector)`
  (`UnknownProductException`), then the existing rules unchanged, addressing inventory by
  `$product->selector()`. Still returns `new Sale($product, $change)`.
- **`setProduct(Product $product): void`** — `$this->catalogue = $this->catalogue->withProduct($product)`
  (add or reprice). Mirrors `setStock` / `setChange` as a technician operation.
- **`removeProduct(string $selector): void`** — resolve via catalogue (`UnknownProductException` if
  absent); if `inventory->countOf(selector) > 0` → `ProductInStockException`; else drop from both
  catalogue and inventory.
- **`setStock(string $selector, int $count): void`** — now selector-based, and **validates against the
  catalogue** (`catalogue->get` throws `UnknownProductException`) so you cannot stock an undefined
  product. Because service mode applies products before stock, "define + stock in one command" works.
- Read helpers become selector-based: `isAvailable(string $selector)`, `stockOf(string $selector)`.
- **`catalogue(): ProductCatalogue`** — exposes the catalogue for the read models.

## Application (`VendingMachine\Application\…`)

### `ViewState` (customer read model)

- `ViewStateHandler` iterates **`$machine->catalogue()->all()`** instead of `Product::cases()`, building
  `ProductState(selector, price, isAvailable(selector))`. `ProductState` and `ViewStateResponse`
  unchanged.

### `BuyProduct`

- `BuyProductHandler` drops `Product::fromSelector`; passes the raw selector straight to
  `$machine->buy($command->productSelector)`. `UnknownProductException` now surfaces from the aggregate
  (same type, so the console's existing `catch` is unaffected).

### `ServiceMachine` (technician write + report)

- **`ServiceMachineCommand`** grows two fields alongside the existing count maps:
  - `array<string,int> $productPrices` — selector → price in cents (define / reprice).
  - `list<string> $productRemovals` — selectors to remove.
- **`ServiceMachineHandler`** — extends the existing *validate-everything-first-then-apply* shape so
  servicing stays atomic:
  1. Pre-validate: build `Product::new(...)` for every price entry (`InvalidProductException` joins
     `UnknownProductException` / `InvalidCoinException` as a pre-validation failure), resolve every
     stock selector and coin as today.
  2. Apply order matters: **`setProduct` first, then `setStock`, then removals, then `setChange`.**
     Products-before-stock lets a new product be defined and stocked in one command; removals-after-stock
     let a technician zero a slot and remove it in the same command.
  3. `save`, then return the `MachineReport`.
- **`MachineReport`** report loop iterates `$machine->catalogue()->all()` instead of `Product::cases()`.
  `ProductStock` / `CoinStock` unchanged. An empty command stays a valid no-op that returns the current
  report (still how the technician `state` view is rendered).

## Infrastructure (`VendingMachine\Infrastructure\…`)

### `Cli\VendingMachineConsole` — two new staged service commands

- New service-mode drafts alongside the existing pending maps:
  - `array<string,int> $pendingProductPrices` (selector → cents)
  - `list<string> $pendingProductRemovals`
- New staged commands (parse/stage only; validity is decided by the handler on `apply`, like `stock`):
  ```
  product <selector> <price>   define or reprice a product   (e.g. product cola 1.25)
  remove  <selector>           remove a product (must be empty)   (e.g. remove cola)
  ```
  Reuse `parseCents` for the price. Update the service-mode banner, the `apply` payload
  (`ServiceMachineCommand(... , $pendingProductPrices, $pendingProductRemovals)`), the discard-on-exit
  reset, and add `InvalidProductException | ProductInStockException` to the `applyService` catch so a
  bad definition or a still-stocked removal reports "Nothing changed." and keeps the draft.
- `availableProducts()` (customer menu / unknown-product message) must stop reading `Product::cases()`;
  source it from a `ViewState` query response instead, so the menu reflects the live catalogue.
- Passcode gating, `X.XX` formatting and all customer commands are unchanged — a customer still cannot
  reach `product` / `remove`.

### `bin/vending-machine` — bootstrap builds a catalogue

- Build a `ProductCatalogue` seeded with Water (65), Juice (100), Soda (150) via `Product::new(...)`,
  seed `Inventory` by selector string, and call
  `VendingMachine::stocked($catalogue, $inventory, $coinBank)`. No customer-facing behaviour changes.

## Tests (Pest)

- **Unit — domain:**
  - Rewrite `ProductTest` for the VO: `new` normalises the selector, rejects empty selector and
    non-positive price (`InvalidProductException`), exposes `selector`/`price`.
  - New `ProductCatalogueTest`: add, reprice (replace), `get`/`has`, unknown selector throws,
    case-insensitive resolution, `withoutProduct` removes / throws on unknown.
  - `InventoryTest`: adapt to selector-string API; `without` drops a slot.
  - `VendingMachineTest`: `buy(selector)` happy path and unknown-selector throw; `setProduct` visible
    via `catalogue()`; `setStock` on an undefined product throws; `removeProduct` forbidden while
    stocked (`ProductInStockException`) and succeeds once emptied.
- **Behaviour — application:**
  - `ServiceMachineHandlerTest`: define + stock a new product in one command; reprice shows in the
    report; atomicity on an invalid price (nothing changes); remove a product (and the still-stocked
    removal is rejected atomically).
  - `BuyProductHandlerTest` / `ViewStateHandlerTest`: adapt to selector-based buy and catalogue-sourced
    listing.
- **Integration — CLI:**
  - `VendingMachineConsoleTest`: in service mode, `product cola 1.25` + `stock cola 3` + `apply` makes
    COLA appear in the technician report and, back in customer mode, buyable; `remove` on a stocked
    product is refused, then works after `stock cola 0`.

Every test constructing `Product::Water` etc. changes to `Product::new('WATER', 65)` (or buys by
selector) — mechanical but broad. Run `composer test` after each layer.

## Verification

1. `docker compose exec -T app composer test` → all suites green.
2. `docker compose exec -T app composer stan` → PHPStan clean.
3. `docker compose exec -T app composer cs` → PSR-12 clean.
4. Manual REPL smoke test:
   ```
   printf 'service\n<code>\nproduct cola 1.25\nstock cola 3\napply\nstate\nexit\n1\n0.25\nget cola\nstate\nexit\n' \
     | docker compose exec -T app php bin/vending-machine
   ```
   Expect: COLA defined + stocked in one apply, shown in the technician report, then buyable by a
   customer and listed in customer `state`.

## Suggested commits (branch `feat/dynamic-products`, nothing committed without your review)

1. `feat: product and catalogue as domain values` — `Product` VO, `ProductCatalogue`,
   `InvalidProductException`, `ProductInStockException`, selector-based `Inventory` + unit tests.
2. `feat: machine sells from an editable catalogue` — `VendingMachine` catalogue field, `buy(selector)`,
   `setProduct` / `removeProduct` / selector-based `setStock`; `BuyProduct` + `ViewState` handlers;
   `bin` bootstrap + tests.
3. `feat: technician defines products in service mode` — `ServiceMachineCommand`/`Handler` prices +
   removals, console `product` / `remove` commands + behaviour and integration tests.
4. (optional) `docs: add dynamic products plan`.

## Out of scope

- Persisting the catalogue across processes (the next workstream; the catalogue/inventory split here is
  what makes that clean).
- Dynamic coins (denominations stay a fixed enum).
- Real authentication (the service passcode remains a simulation of physical access).
