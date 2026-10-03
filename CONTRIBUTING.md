# Contributing to garlic-analytics

Thanks for your interest in garlic-analytics. This document explains how to set up the project, which checks your change has to pass and which conventions the code follows.

garlic-analytics is in early development. Planned work is listed in [ROADMAP.md](ROADMAP.md) and tracked as GitHub milestones. Read [docs/architecture.md](docs/architecture.md) before larger changes, it describes the target design and the rules behind it.

## Reporting issues and proposing changes

- Bugs and feature requests go into the GitHub issue tracker.
- For larger changes or anything that touches the architecture, open an issue first so the approach can be discussed before you write code.
- Pull requests target `main`.

## Setup

Requirements:

- PHP 8.4
- Composer
- ClickHouse, only needed when working on the database layer

```bash
git clone https://github.com/garlic-signage/garlic-analytics.git
cd garlic-analytics
composer install
cp .env.dist .env
```

Fill in `.env`, at least `APP_ENV=dev` and `APP_DEBUG=true`. The ClickHouse settings are only needed if you run migrations or touch the database.

### DDEV (optional)

The repository contains a [DDEV](https://ddev.com) configuration with a ClickHouse service:

```bash
ddev start
ddev exec -s clickhouse clickhouse-client --user analytics --password analytics     # ClickHouse client inside the container
```

## Checks

Every pull request must pass both checks. They run on GitHub after every push and for every pull request, with the same commands you use locally:

```bash
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

- PHPStan runs at level `max` with strict rules and covers `src`, `tests`, `config` and `public`.
- PHPUnit fails on warnings, risky tests and unexpected output.

Run a single test:

```bash
vendor/bin/phpunit --filter testMethodName
vendor/bin/phpunit tests/Framework/Core/CryptTest.php
```

## Tests

- Tests live in `tests/` and mirror the structure of `src/` (namespace `Tests\` ↔ `App\`).
- Every test method needs the attribute `#[Group('units')]`. PHPUnit only runs this group, a test without it is skipped silently.
- Use a stub when the test only needs return values. Use a mock only when the call itself is what you test, e.g. that a configuration file is loaded only once.
- Code that talks to ClickHouse depends on `ClickHouseClientInterface`, so tests can replace it without a running database.
- Coverage is measured locally when needed. There is no threshold.

## Code style

Follow the style of the existing files:

- Every PHP file starts with the AGPL license header used in the other files, followed by `declare(strict_types=1);`.
- Braces on their own line (Allman style). Single-statement `if` and `foreach` bodies are written without braces.
- Describe array types in PHPDoc (`array<string,string>`, `list<string>`), PHPStan needs them at level `max`.
- New services are registered as `DI\factory(...)` entries in a file under `config/services/`. Every PHP file in that directory is loaded automatically.

## Database schema

- The schema is defined by SQL files in `migrations/`, executed in file name order by `bin/console db:migrate`.
- Before the first file the runner checks the connection and creates the database from `CLICKHOUSE_DATABASE` if it is missing (the user needs the right to create databases for that).
- The runner executes every file on every run. All statements must be idempotent: `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS` and so on.
- Do not change a statement that has already been released. Add a new file instead, otherwise existing installations never receive the change.
- Statements are split at every `;` and only full-line `--` comments are removed. Do not use `;` inside string literals and do not put comments behind code on the same line.
- Clients send individual events, never pre-aggregated values. Aggregation happens in ClickHouse through materialized views into hourly tables.

## License

garlic-analytics is licensed under the AGPL-3.0, see [LICENSE](LICENSE). By submitting a contribution you agree that it is published under the same license.
