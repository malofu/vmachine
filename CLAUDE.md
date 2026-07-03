# CLAUDE.md

It is mandatory that you don't do any commit without my revision.

In this project we are implementing a vending machine. We are using Docker and plain PHP 8.3.

- **Architectural pattern:** hexagonal architecture.
- **Design approach:** DDD.
- **Coding style:** PSR-12.

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

The folder structure will be separated per context, with `infrastructure`, `application` and `domain` inside each one. E.g. `/src/VendingMachine/domain`, `/src/VendingMachine/application`, `/src/VendingMachine/infrastructure`, and so on.
