# Architecture

garlic-analytics is a self-hosted analytics service for digital signage players. It collects high-volume data produced by players (proof-of-play reports, logs, connection events and further event types), stores it in ClickHouse, aggregates it and serves reports and aggregated data.

## Components

The project will consist of two components in one repository:

- **API** (`api/`): accepts normalized events, writes them to ClickHouse and serves reports and aggregates. Not public. Reachable only by CMS instances and the collector.
- **Collector** (`collector/`): public. Players upload their logs directly via WebDAV. The collector normalizes them using adapters and forwards them to the API.

If your CMS normalizes player data itself, it only talks to the API and does not need the collector. If you want players to upload directly, run the collector as well.

## Data flow

```
Option 1: normalized by the client
  CMS normalizes player data -> POST /v1/events (API)

Option 2: direct upload from players
  Player -> WebDAV (collector) -> inbox/
    -> collector normalizes via adapter
    -> POST /v1/events (API, internal network)

API -> ClickHouse: event tables + materialized views (aggregates)
CMS -> GET /v1/stats/... (API) -> displays results, resolves IDs to names and thumbnails
```

- There is exactly one way into the database: the ingest API. The collector never writes to ClickHouse directly. To the API it is a client like any CMS.
- Clients send individual events (one entry per playback or occurrence), never pre-aggregated values. Aggregation happens exclusively in ClickHouse.
- Aggregation is done with materialized views, there is no separate aggregation job. Hourly aggregates are stored, daily, monthly and yearly values are computed from them.
- Retention is handled with TTL per event type: individual events are kept for up to 180 days, aggregates permanently or considerably longer.
- Every table contains a `tenant_id`, so one instance can serve multiple CMS installations.
- ClickHouse ports are never exposed to the outside.

## API

- Not public. Reachable only from the internal network or restricted to specific addresses by firewall.
- `POST /v1/events`: one request per source file, normalized events as JSON, gzip supported. Maximum number of events per request (guideline 5,000), larger batches are split by the client.
- Responses:
  - `2xx`: success
  - `4xx`: invalid data, the client must not retry unchanged
  - `5xx`: server problem, the client retries later
- `GET /v1/stats/...`: fixed endpoints with parameters, no free-form SQL. Responses contain only IDs and numbers, no names.
- `GET /v1/health`: no authentication, also checks the ClickHouse connection.
- Errors are returned as JSON with a matching HTTP status and a meaningful message.
- The schema is defined by idempotent SQL files in `migrations/` (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`). A runner (`bin/migrate.php`) executes all files in order on startup. New tables and columns therefore reach existing installations automatically.
- The ClickHouse connection is configured only via environment (`CLICKHOUSE_HOST`, `CLICKHOUSE_PORT`, `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, `CLICKHOUSE_DATABASE`). ClickHouse can run on the same host or a separate one.

## Collector Step 2.

- Publicly reachable for players.
- Accepts uploads via WebDAV or HTTP PUT and stores them in `inbox/`.
- Player access is configured per tenant. The player ID is taken from the file name or path.
- Normalization is done by adapters, one per player format. An adapter translates a source-specific format (e.g. the XML report of a specific player) into the event format.
- Sends normalized events to the API with its own API key (scope `ingest`).
- File processing:
  - Directories on the same volume: `inbox/`, `processed/`, `error/`. Files are moved with `rename()` (atomic).
  - Files are processed sequentially, one request per file. Very large files are split into blocks.
  - After `2xx`: file is moved to `processed/`.
  - After `4xx` or an adapter error: file is moved to `error/`, together with `<file>.error` containing the message and timestamp.
  - After `5xx` or a network error: file stays in `inbox/` and is retried on the next run.
  - Concurrent runs are prevented by a lock.
  - `processed/` is cleaned up after a fixed period (guideline 30 days). This is the window for reprocessing.
  - Reprocessing: move files from `error/` or `processed/` back to `inbox/`. Idempotency in the API prevents double counting.
- The collector is optional and started via a Docker Compose profile.

## Request handling

- Middleware wraps the controller like the layers of an onion. The one added last is the outermost and runs first:

  Request
  -> ErrorMiddleware          (added last, runs first)
  -> RoutingMiddleware
  -> BodyParsingMiddleware
  -> Controller

- BodyParsingMiddleware converts a JSON request body into an array.
- RoutingMiddleware finds the route for the URL. It throws 404 or 405 if there is none.
- ErrorMiddleware is added last. Only as the outermost layer it can catch everything thrown further inside, including the 404 from routing.

## Error handling

- Exceptions and Errors (thrown exceptions, TypeError, method call on null) are caught by the error middleware.
- Warnings and notices are converted into an `ErrorException` by `set_error_handler`, so they end up in the error middleware too. Not converted: errors suppressed with `@` and deprecations. Deprecations are written to the PHP log.
- Fatal errors (memory limit, max execution time) cannot be caught.
- Every exception becomes a JSON response:
  - `Slim\Exception\HttpException`: its own status code and message
  - `ValidationException`: `422`
  - anything else: `500` with a fixed text
- Only `5xx` responses are logged.
- The real message of a `500` is only returned when `APP_DEBUG=true`.

## Configuration

- Environment: `APP_ENV` sets the log level (`dev` from debug, `prod` from error, anything else from info). `APP_DEBUG=true` adds the real error message to `500` responses.
- Module settings: one INI file per module, named `config_<module>.ini`. A file is loaded on first access and cached for the rest of the request.
- All INI values are strings. Numbers are cast by the caller.
- A missing or broken INI file throws a `CoreException`.
- Both sources are read through `App\Framework\Core\Config\Config`.

## Tech stack

- PHP 8.4 with strict types
- Slim 4, PHP-DI
- ClickHouse via its HTTP interface (`smi2/phpclickhouse`), no ORM
- PHPUnit, PHPStan at the highest level with strict rules
- Docker: official `clickhouse/clickhouse-server` image with a pinned version, API and collector containers based on FrankenPHP

## Quality checks

- PHPUnit and PHPStan run on GitHub after every push and for every pull request. There is one workflow per tool in `.github/workflows/`, so each has its own status badge.
- Both can be run locally with the same commands the workflows use.
- Tests use a stub when they only need return values. A mock is used only when the call itself is tested, e.g. that a configuration file is loaded only once.
- Coverage is measured locally when needed. There is no threshold and no badge.
