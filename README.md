# Vending Machine

A vending machine simulation, modeled with **hexagonal architecture** and **DDD** in plain **PHP 8.3**.
The entry point is a CLI; all state is kept in memory.

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
