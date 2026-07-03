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
├── domain/          # Entities, value objects and business rules
├── application/     # Use cases orchestrating the domain
└── infrastructure/  # CLI entry point and adapters

tests/
├── Unit/            # Domain unit tests
├── Behaviour/       # Application-level behaviour tests
└── Integration/     # Infrastructure integration tests
```

## Conventions

- Amounts are handled as **integer cents** (no floats).
- Coding style: **PSR-12**.
- Commits follow `type: message` (e.g. `feat:`, `fix:`, `chore:`).
