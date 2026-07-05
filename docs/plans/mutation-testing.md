# Plan — Mutation Testing (branch `chore/mutation-testing`)

**Goal:** verify the test suite catches planted bugs, systematically. Infection mutates `src/`
(flips operators, deletes statements, swaps returns) and reports mutants the tests failed to kill.
This hardens the suite *before* the dynamic-catalogue refactor relies on it as a safety net.

Verified starting point: the Dockerfile has no coverage driver (no pcov/xdebug), CI runs with
`coverage: none`, and `.gitignore` has no `var/` entry.

---

## Commit 1 — `chore: add pcov to the container for coverage`

- Dockerfile: add after the existing `apk add` line:

  ```dockerfile
  RUN apk add --no-cache $PHPIZE_DEPS \
      && pecl install pcov \
      && docker-php-ext-enable pcov \
      && apk del $PHPIZE_DEPS
  ```

- Rebuild: `docker compose build`.
- Verify: `docker compose exec app php -m | grep pcov`.

pcov is fast and provides line coverage, which is all Infection needs.

## Commit 2 — `chore: add infection mutation testing`

- Dev dependency: `composer require --dev infection/infection` (run inside the container).
  - Infection ships the `infection/extension-installer` composer plugin — it must be added to
    `allow-plugins` in `composer.json` so the Pest adapter is discovered.
- `infection.json5` at the repo root:

  ```json5
  {
      "$schema": "vendor/infection/infection/resources/schema.json",
      "source": { "directories": ["src"] },
      "testFramework": "pest",
      "logs": { "text": "var/infection.log", "summary": "var/infection-summary.log" },
      "minMsi": 85,          // placeholder — tune after the first run, just below the achieved score
      "minCoveredMsi": 90
  }
  ```

- Composer script: `"infection": "infection --threads=max --show-mutations"`.
- Add `/var/` to `.gitignore` (Infection log output).

## Commit 3 — `test: kill surviving mutants`

1. Run `docker compose exec app composer infection`; read `var/infection.log`.
2. Triage escaped mutants. Kill the meaningful ones with new tests; known interesting areas:
   - `CoinBank::compose()` boundaries (`>` vs `>=` on `$cents > $amount`, the count check).
   - `VendingMachine::buy()` guard order and the `<` in the insufficient-money check.
   - `parseCents()` in the console (regex/`str_pad` edges: `"0.5"` → 50, `"1"` → 100, `"1.5"` → 150).
   - The three TASK.md example flows as literal end-to-end console tests, if not already exact.
3. Ignore noise mutants (message-string tweaks, greet text) sparingly — prefer killing over
   ignoring.
4. Set `minMsi` to just under the final score so the number is enforceable and honest.

---

## Out of scope

- **No CI step for Infection** — mutation runs are slow; the value is the one-off audit plus the
  enforced thresholds. (If it ever runs in CI, `setup-php` would need `coverage: pcov`.)
- No changes to the existing quality pipeline (`cs`, `stan`, `test`).

## Done when

- `composer infection` runs green against the tuned `minMsi`/`minCoveredMsi` thresholds.
- Full pipeline still passes: `composer cs && composer stan && composer test`.
- Three commits as above, each reviewed before committing (nothing is committed without review).
