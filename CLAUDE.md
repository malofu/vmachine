# CLAUDE.md

In this project we are implementing a vending machine. We are using Docker and plain PHP 8.3.

- **Architectural pattern:** hexagonal architecture.
- **Design approach:** DDD.
- **Coding style:** PSR-12.

## Rules

- Not make any commit without my review.
- Keep the solution simple. Do not add patterns, layers or infrastructure the task does not need.
- Ask before adding any dependency.
- Plain PHP + Composer for this scope.
- State is kept behind the `VendingMachineRepository` port. Two adapters ship: in-memory (the default,
  used by the tests and a plain run) and SQLite (opt-in via `PERSISTENCE=sqlite`, state survives restarts).
- The entry point will be a CLI for this scope.
- Amounts have to be treated as cents, use integers.

## Git workflow

We are using GitHub. Every use case will have its own branch with different commits. I will review every commit by myself before pushing it. That branch will be merged to `dev` using a pull request, which also will be reviewed by myself.

Use a convention per commit where the thing done is indicated before the message, something like `feat: xxxx`, `fix: yyyy`, etc.

## Environment

We need to install Docker, and inside the container we will be using PHP 8.3, Composer, Pest for testing, PHPStan, and CI via GitHub Actions.

## Testing strategy

- Unit tests for the domain.
- Behaviour tests at the application level.
- Integration tests for the infrastructure.

## Folder structure

The folder structure will be separated per context, with `Infrastructure`, `Application` and `Domain` inside each one. E.g. `/src/VendingMachine/Domain`, `/src/VendingMachine/Application`, `/src/VendingMachine/Infrastructure`, and so on.

Layer folders are StudlyCaps so they match their namespace segment (`VendingMachine\Domain\...`): PSR-4 autoloading is case-sensitive on Linux/CI, so a lowercase folder with a StudlyCaps namespace would fail to autoload there.

## User stories

### 1. Insert coin
As a **customer**, I want to **insert coins one at a time**, so that **I can build up credit toward a product**.
- Accepted coins: 0.05, 0.10, 0.25, 1.00.
- Any other denomination is rejected.

### 2. Return coins
As a **customer**, I want to **get my inserted coins back**, so that **I can change my mind before buying**.
- Returns all money inserted so far.

### 3. Buy product
As a **customer**, I want to **select and buy a product**, so that **I receive the item, plus any change I'm owed**.
- Products: Water (0.65), Juice (1.00), Soda (1.50).
- On success: dispense the item and return any change.
- Fails if the product is out of stock.
- Fails if the inserted money is insufficient.
- Fails if the machine cannot compose the exact change.

### 4. View machine state
As a **customer**, I want to **see the current state (my balance, stock and prices)**, so that **I know what I can buy**.
- Shows inserted balance, available products with prices, and stock.

### 5. Service the machine
As a **service technician**, I want to **refill products and set the available change**, so that **the machine can keep serving customers**.
- Sets item counts and the available change in the coin bank.

## Glossary

- **Coin** — a single accepted denomination: 0.05, 0.10, 0.25 or 1.00.
- **Money** — an amount of value, expressed as a sum of coins.
- **Product** — an item for sale, with a name, a price and a selector (Water, Juice, Soda).
- **Inventory** — the stock of products the machine holds, each with a count.
- **CoinBank** — the coins the machine holds and uses to give change.
- **Change** — the coins returned to the customer when they pay more than the price.
- **VendingMachine** — the machine as a whole: it holds the products, the coins and the currently inserted money, and enforces the rules of a sale.