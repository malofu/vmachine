# Vending Machine

A vending machine simulation, modeled with **hexagonal architecture** and **DDD** in plain **PHP 8.3**.
The entry point is a CLI. State lives behind a repository port, with two adapters: **in-memory** by
default, or **SQLite** to persist across restarts (see [Persistence](#persistence)).

## Requirements

- [Docker](https://www.docker.com/) and Docker Compose

Everything else (PHP 8.3, Composer, Pest, PHPStan) runs inside the container — nothing to install on the host.

## Getting started

Build the image and install dependencies:

```bash
docker compose build
docker compose up -d
docker compose exec app composer install
```

## Running the machine

The CLI needs a service passcode (it gates technician mode). Copy the example env file and run:

```bash
cp .env.example .env
docker compose exec app php bin/vending-machine
```

Type `state` to see the products, insert coins by typing a value (e.g. `0.25`), buy with `get water`,
and `return` your coins. `service` opens technician mode (using the `SERVICE_CODE` from `.env`).

## Persistence

By default the machine keeps its state **in memory** — it resets every time the process exits. Set
`PERSISTENCE=sqlite` (in `.env` or the environment) to keep state in a **SQLite** database that survives
restarts:

```bash
PERSISTENCE=sqlite docker compose exec app php bin/vending-machine
```

| Variable      | Default              | Meaning                                                        |
| ------------- | -------------------- | -------------------------------------------------------------- |
| `PERSISTENCE` | `memory`             | `memory` (ephemeral) or `sqlite` (durable).                    |
| `DB_PATH`     | `data/machine.sqlite`| SQLite file, used only when `PERSISTENCE=sqlite`. Relative paths resolve from the project root; the file is created on first run. |

The database schema and its default seed data live as plain SQL in [`data/schema.sql`](data/schema.sql)
and [`data/seed.sql`](data/seed.sql). A fresh database is created and seeded automatically on first run;
an existing one is loaded as-is, so the machine resumes exactly where it left off.

## Running the tests

```bash
docker compose exec app composer test    # Pest
docker compose exec app composer stan    # PHPStan static analysis
```

## Project structure

Code is organized per bounded context, each split into the hexagonal layers:

```
src/VendingMachine/
├── Domain/          # Entities, value objects and business rules
├── Application/     # Use cases orchestrating the domain
└── Infrastructure/  # CLI entry point and adapters
```

Tests mirror that layout per context, and the PHPUnit suites map onto the layers:

```
tests/VendingMachine/
├── Domain/          # Unit suite         — domain unit tests
├── Application/     # Behaviour suite     — application-level behaviour tests
└── Infrastructure/  # Integration suite   — infrastructure integration tests
```

## Conventions

- Amounts are handled as **integer cents** (no floats).
- Coding style: **PSR-12**.
- Commits follow `type: message` (e.g. `feat:`, `fix:`, `chore:`).
