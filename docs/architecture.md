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
  CMS normalizes player data -> POST /v1/<module> (API), e.g. POST /v1/playlog

Option 2: direct upload from players
  Player -> WebDAV (collector) -> inbox/
    -> collector normalizes via adapter
    -> POST /v1/<module> (API, internal network)

API -> ClickHouse: event tables + materialized views (aggregates)
CMS -> GET /v1/<module>/... (API) -> displays results, resolves IDs to names and thumbnails
```

- There is exactly one way into the database: the ingest API. The collector never writes to ClickHouse directly. To the API it is a client like any CMS.
- Clients send individual events (one entry per playback or occurrence), never pre-aggregated values. Aggregation happens exclusively in ClickHouse.
- Aggregation is done with materialized views, there is no separate aggregation job. Hourly aggregates are stored, daily, monthly and yearly values are computed from them.
- Retention is handled with TTL per event type: individual events are kept for a limited time (play logs 2 years, player events and system reports 6 months, connects 3 months), aggregates permanently or considerably longer.
- There is no tenant separation. An instance belongs to one CMS installation, data is assigned to players by their player ID. CMS installations that must not see each other's data run separate instances.
- ClickHouse ports are never exposed to the outside.

## API

- Not public. Reachable only from the internal network or restricted to specific addresses by firewall.
- Every module provides its own endpoints: one for ingest and one or more for reading, e.g. `POST /v1/playlog` and `GET /v1/playlog/{player_id}`. See [Modules](#modules).
- Ingest: one request per source file, normalized events as JSON (`{"events": [...]}`), gzip supported.
- Idempotency: one request is one `INSERT`. Its `insert_deduplication_token` is a hash of the events, together with `deduplicate_blocks_in_dependent_materialized_views=1` the same request sent again (client retry) is dropped, also in the hourly tables (they need `non_replicated_deduplication_window`). Overlapping but different batches are not detected. The answer is `201` in both cases. The window is 1,000,000 inserts, a request must stay one block (limit of events per request).
- Responses:
  - `2xx`: success
  - `4xx`: invalid data, the client must not retry unchanged
  - `5xx`: server problem, the client retries later
- Read endpoints: fixed parameters, no free-form SQL, e.g. aggregated data of one player for a time range, paginated. Responses contain only IDs and numbers, no names.
- `GET /v1/health`: no authentication, also checks the ClickHouse connection.
- Errors are returned as JSON with a matching HTTP status and a meaningful message.
- The schema is defined by idempotent SQL files in `migrations/` (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`). A runner (`bin/console db:migrate`) executes all files in order on startup. New tables and columns therefore reach existing installations automatically.
- The ClickHouse connection is configured only via environment (`CLICKHOUSE_HOST`, `CLICKHOUSE_PORT`, `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, `CLICKHOUSE_DATABASE`). ClickHouse can run on the same host or a separate one.

## Modules

- Code is organized in modules under `src/Modules/<Module>/`: one module per event type (e.g. `PlayLog`, `EventLog`, `PlayerConnect`, `SystemLog`) plus `Auth` and `Health`.
- Each module has a controller for its routes, the validation of its events and a repository with the inserts and queries for its tables.
- Concerns shared by all modules (authentication, gzip, batch ID as deduplication token, limit of events per request) are implemented once as middleware or in `src/Framework/`, not repeated in every module.
- The ingest of all modules shares its parts, a module only adds what is specific to its event type:
  - `Framework\Ingest\IngestController` and `IngestService`: base classes. A module extends them (`PlayLogController`, `PlayLogService`) and delivers a validator and a repository through the interfaces `IngestValidatorInterface` and `IngestRepositoryInterface`.
  - `Framework\Validation\BatchValidator`: the checks around the event list (list, limit of events, collected errors as `events.<index>.<field>`, all or nothing) and the common field checks (strings, ISO 8601 time, too old, in the future). The module validator checks the fields of its event type.
  - `Framework\Database\BatchRepository`: base of the repositories. It writes the rows with one `INSERT` and builds the deduplication token from them.
  - `ClickHouseClient` writes a PHP array with string keys as a `Map` value (`{'key':'value'}`).
- Read endpoints aggregate first (`sum()` with `GROUP BY` on the hourly tables) and paginate the aggregated result.
- Routes stay central in `config/routes.php`, schema files stay central in `migrations/`.

## Ingest formats

The body of every ingest request is `{"events": [...]}`. Times are ISO 8601 to the second with an offset (`2026-10-03T15:30:27+02:00` or `Z`, no fractions of seconds), the API stores them in UTC. The answer is `201 {"accepted": n}`, invalid data gives `422` with the errors of all events. The limits are set per module in `config/settings/config_<module>.ini` (`max_events`, `max_age_days`, `max_future_seconds`).

**`POST /v1/playlog`** (table `play_log`, kept 2 years)

| Field | Description |
|---|---|
| `player_id` | String, up to 128 characters |
| `content_id` | String, up to 128 characters |
| `start_time`, `end_time` | `end_time` must not be before `start_time` |

**`POST /v1/eventlog`** (table `event_log`, kept 6 months, `max_age_days` 180)

| Field | Description |
|---|---|
| `player_id` | String, up to 128 characters |
| `event_time` | Time of the event |
| `event_type` | One of `debug`, `informational`, `notice`, `warning`, `error`, `critical`, `fatal`. The client normalizes to these names, the API rejects others. |
| `event_source`, `event_name` | Strings, up to 128 characters |
| `metadata` | Optional object with string values: up to 20 entries, key up to 64, value up to 1024 characters. Stored as an empty map if missing. |

**`POST /v1/systemlog`** (table `system_log`, kept 6 months, `max_age_days` 180)

One entry is one system report.

| Field | Description |
|---|---|
| `player_id` | String, up to 128 characters |
| `reported_at` | Creation time of the report. Checked against `max_age_days` and the future. |
| `system_start` | Boot time of the player. Not checked for age, a player can run longer than the retention. |
| `time_zone` | String, up to 64 characters, stored as sent (players send e.g. `MEZ` as well as `Europe/Berlin`) |
| `disk_total`, `disk_free` | Integers (bytes), 0 or more |
| `cpu_usage` | Optional integer, 0 to 100 |
| `memory_total`, `memory_used` | Optional integers (bytes), 0 or more |
| `hdmi_output` | Optional string, up to 64 characters |

Optional values a player does not report are stored as `NULL` (`hdmi_output`: empty). The values are not compared with each other, a player with a measuring error keeps its report.

Example for `POST /v1/eventlog`:

```json
{"events": [{
  "player_id": "player-1",
  "event_time": "2026-10-03T15:30:27+02:00",
  "event_type": "warning",
  "event_source": "ContentManager",
  "event_name": "FETCH_FAILED",
  "metadata": {"resourceURI": "https://example.com/a.jpg", "errorMessage": "Host not found"}
}]}
```

## Authentication

- One API key per client, sent as `Authorization: Bearer <key>`. Never as a query parameter.
- Keys are created by a CLI command with `bin2hex(random_bytes(32))` and shown only once. Only the SHA-256 hash is stored, together with the client name and its scopes. Comparison with `hash_equals()`.
- The hashes are stored in a file in `var/keys/`, outside the docroot and outside the repository. Access goes through an interface, so the storage can be replaced later.
- Scopes: `ingest` for writing, `read` for reading. The collector only gets `ingest`.
- Missing or unknown key: `401`. Key without the required scope: `403`. `GET /v1/health` needs no key.
- Keys are managed with `bin/console apikey:create|list|revoke`, see [cli.md](cli.md).
- Apache only passes the `Authorization` header to PHP with `CGIPassAuth On` (set in `public/.htaccess`).

## Collector Step 2.

- Publicly reachable for players.
- Accepts uploads via WebDAV or HTTP PUT and stores them in `inbox/`.
- Normalization is done by adapters, one per player format. An adapter translates a source-specific format (e.g. the XML report of a specific player) into the event format.
- Sends normalized events to the API with its own API key (scope `ingest`).
- The type of a file follows from its name (`LogType`: `playlog`, `event`, `system`) and decides the endpoint (`LogType::endpoint()`). Play logs (`/v1/playlog`), events (`/v1/eventlog`) and system reports (`/v1/systemlog`) are sent.
- Every type has a record class (`RecordInterface`: `PlayLogRecord`, `EventLogRecord`) in the format of its endpoint. `IngestClientInterface::send(LogType, records)` and `DeviceAdapterInterface::parse(LogType, file)` work for all types, a new type does not need new methods.
- Of a system report only the values of `system_log` are sent. `network` (MAC and IP addresses), `configuration` (it contains passwords) and `hardwareInfo` never leave the collector. The adapter also normalizes the format of the player: the offset `+0200` becomes `+02:00`, a cpu usage `31%` becomes `31`.
- The SMIL adapter reads the files with one parser per type on a common base (`SmilReportParser`). A file is read completely before anything is sent: one broken event (a missing field, broken XML) sends the whole file to `error/`.
- File processing:
  - Directories on the same volume: `inbox/`, `processed/`, `error/`. Files are moved with `rename()` (atomic).
  - Files are processed sequentially, one request per file. Very large files are split into blocks.
  - After `2xx`: file is moved to `processed/`.
  - After `400`, `413` or `422`, or an adapter error: file is moved to `error/`, together with `<file>.error` containing the message and timestamp. Other `4xx` (`401`, `403`, `404`, `405`) point to a wrong key, rights or URL of the collector, not to the data, so the file stays and the run stops.
  - After `5xx`, `408`, `429` or a network error: file stays in `inbox/`, the run stops (the next files would fail the same way) and is retried on the next run.
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
  - `ValidationException`: `422`, with the messages per field in `errors`
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
