# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Self-hosted analytics service for digital signage players (part of the GarlicSignage stack). Collects proof-of-play reports, player logs, connection events and system reports in ClickHouse and exposes a non-public REST API for CMS integration. Early development: framework, config, error handling, migrations and CI exist; ingest/stats endpoints do not yet. See `docs/architecture.md` for the target design and `ROADMAP.md` for planned milestones.

Stack: PHP 8.4 (`declare(strict_types=1)` everywhere), Slim 4, PHP-DI, monolog, `smi2/phpclickhouse` (ClickHouse HTTP interface, no ORM), PHPUnit 13, PHPStan level max with strict rules.

## Working rules

The maintainer's binding rules (from the local, untracked `PROJECT-de.md`, the German project spec):

- Work in phases. Before writing code for a phase, present a plan (files, decisions, open questions) and wait for approval. After a phase, stop, summarize and wait.
- Do not add dependencies without asking.
- Ask instead of guessing, especially for the schema.
- Never run PHP, Composer, PHPUnit or PHPStan on the host (there is no PHP there). Always go through DDEV.

## Commands

```bash
ddev composer install
ddev exec vendor/bin/phpunit                       # all tests (CI runs vendor/bin/phpunit)
ddev exec vendor/bin/phpunit --filter testName     # single test
ddev exec vendor/bin/phpunit tests/Framework/Core/CryptTest.php
ddev exec vendor/bin/phpstan analyse               # CI runs with --no-progress; `composer stan` adds --memory-limit=1G
ddev exec bin/console db:migrate                   # apply all migrations/*.sql to ClickHouse
ddev exec bin/console apikey:create <name> --scope=ingest --scope=read   # prints the key once
ddev exec bin/console apikey:list
ddev exec bin/console apikey:revoke <name>
```

Both PHPUnit and PHPStan must be green (separate GitHub workflows, one per tool). PHPStan analyses `src`, `tests`, `config` and `public`, so config/route files must type-check too.

Local dev environment is DDEV (`.ddev/`): web container plus a `clickhouse/clickhouse-server:25.8` service (db/user/password `analytics`). `ddev clickhouse-client` opens a client in that container.

## Testing conventions

- `phpunit.xml` only includes the group `units`. **Every test method needs `#[Group('units')]`**, otherwise it is silently skipped.
- PHPUnit bootstraps from `vendor/autoload.php` (not `tests/bootstrap.php`). `failOnRisky`, `failOnWarning` and `beStrictAboutOutputDuringTests` are on.
- Use a stub when only return values matter; use a mock only when the call itself is being asserted (e.g. that a config file is loaded once).
- Test namespace `Tests\` mirrors `App\` (`tests/Framework/...` ↔ `src/Framework/...`).

## Architecture

**Modules**: feature code goes in `src/Modules/<Module>/`, one module per event type (`PlayLog`, `PlayerEvent`, …) plus `Auth` and `Health`. Each module owns a controller for its routes (e.g. `POST /v1/playlog` for ingest, `GET /v1/playlog/{player_id}` for paginated aggregates), its validation and a repository. Shared concerns (auth, gzip, dedup token, batch limit) live once in middleware or `src/Framework/`. Routes stay central in `config/routes.php`, schema files in `migrations/`. There is no tenant concept; data is keyed by player ID. API keys: see the Authentication section of `docs/architecture.md`.

**Boot sequence** (`public/index.php` and `bin/console` both `require config/bootstrap.php`, which returns the Slim `App`):
1. Loads `.env` via phpdotenv (immutable) and builds a `$paths` array (`systemDir`, `logDir`, `configDir`, `migrationDir`, …).
2. Registers `Config` first (needs paths + env), then `config/services/_default.php`, then every other `*.php` under `config/services/` (recursively). New DI definitions go in a new/existing file there, returning an array of `DI\factory(...)` entries.
3. `config/middleware.php` registers routes (`config/routes.php`), then body parsing, routing, and finally error handling. Error middleware **must stay last** so it is outermost and catches routing 404/405.

**Error handling** (`config/error_handling.php`): warnings/notices become `ErrorException` (deprecations and `@`-suppressed errors excluded). Every exception becomes JSON `{"error": ...}`: `HttpException` keeps its code, `App\Framework\Exceptions\ValidationException` → 422, everything else → 500 with a fixed message (real message added only when `APP_DEBUG=true`). Only 5xx is logged (`AppLogger`). Throw `ValidationException` for client-data errors.

**Configuration** (`App\Framework\Core\Config\Config`) is the single access point for both env and module settings:
- `getEnv()` — values from `.env` (see `.env.dist`: `APP_ENV` dev|test|prod drives log level, `APP_DEBUG`, `APP_SECRET`, `CLICKHOUSE_HOST/PORT/USER/PASSWORD/DATABASE`). Real environment variables (DDEV `web_environment`, Docker) win over `.env`; `config/bootstrap.php` merges `$_ENV` and `getenv()` into what `Config` receives, because phpdotenv's `load()` omits variables that already exist. `APP_DEBUG` is read via `Config::isDebug()` and passed into `config/error_handling.php` by `config/middleware.php`.
- `getConfigValue($key, $module, $section)` — per-module `config/settings/config_<module>.ini`, loaded lazily and cached. All INI values are strings; callers cast. Missing/broken file throws `CoreException`.
- Loggers are DI entries by string id: `AppLogger`, `ModuleLogger`, `FrameworkLogger` (files in `var/logs/`).

**ClickHouse access**: depend on `App\Framework\Database\ClickHouseClientInterface` (wrapper around `ClickHouseDB\Client`, wired in `config/services/database.php`), not the library directly, so tests can substitute it.

**Schema / migrations**: `migrations/*.sql` are run in filename order by `SchemaMigrator` on every `db:migrate` call, so every statement must be idempotent (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`). Never edit an applied statement expecting it to change existing installs — add a new file. The splitter is naive: it strips only full-line `--` comments and splits on every `;`, so avoid semicolons inside string literals and trailing comments.

**Data model principles** (from `docs/architecture.md`): clients send individual raw events, never pre-aggregated values; aggregation happens only in ClickHouse via materialized views into hourly `SummingMergeTree` tables (`*_hourly`, always query with `sum()` + `GROUP BY`); daily/monthly/yearly are derived from hourly. Raw tables have TTL with `ttl_only_drop_parts`; aggregates have none. Ingest is the only write path into ClickHouse (the planned collector posts to the API like any client). Stats endpoints return IDs and numbers only — the CMS resolves names. Responses: 4xx = client must not retry unchanged, 5xx = retry later.

## Code style

Match existing files: AGPL license header block, `declare(strict_types=1)`, Allman braces, braceless single-statement `if`/`foreach` bodies, PHPDoc array shapes (`array<string,string>`, `list<string>`) to satisfy PHPStan max.
