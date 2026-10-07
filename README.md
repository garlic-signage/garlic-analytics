# garlic-analytics

[![PHPUnit](https://github.com/garlic-signage/garlic-analytics/actions/workflows/phpunit.yml/badge.svg?branch=main)](https://github.com/garlic-signage/garlic-analytics/actions/workflows/phpunit.yml)
[![PHPStan](https://github.com/garlic-signage/garlic-analytics/actions/workflows/phpstan.yml/badge.svg?branch=main)](https://github.com/garlic-signage/garlic-analytics/actions/workflows/phpstan.yml)
[![Integration](https://github.com/garlic-signage/garlic-analytics/actions/workflows/integration.yml/badge.svg?branch=main)](https://github.com/garlic-signage/garlic-analytics/actions/workflows/integration.yml)
![PHPStan Level](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)

Self-hosted analytics service for digital signage players. Collects logs, proof-of-play reports and connection events in ClickHouse and provides a REST API for CMS integration.

Part of the [GarlicSignage](https://github.com/garlic-signage) stack.

## Status

Early development. Working today:

- Ingest of play logs, player events, system reports and connects (`POST /v1/playlog`, `/eventlog`, `/systemlog`, `/connectlog`) into ClickHouse, with validation and idempotent retries
- API key authentication with scopes (`ingest`, `read`)
- Reading of the raw play logs, events and system reports of a player in a time range, paginated (`GET /v1/playlog`, `GET /v1/eventlog`, `GET /v1/systemlog`), the events optionally filtered by severity, source and name
- Proof-of-play statistics of a player from the hourly aggregate: how often and how long each content was played (`GET /v1/playlog/stats`) and the plays per hour, day or month (`GET /v1/playlog/stats/period`)
- Reading of the connects of a player per hour, day (in the time zone of the CMS) or month from the hourly aggregate (`GET /v1/connectlog`) and as raw records for one day (`GET /v1/connectlog/raw`)
- The same statistics for a group of players, the CMS sends the player IDs (`POST /v1/playlog/stats/group`, `/v1/playlog/stats/group/period`, `/v1/connectlog/group`)
- Schema migrations (`bin/console db:migrate`)
- Collector for SMIL player reports: normalizes uploaded files and sends them to the API

Not there yet: aggregates (reports) and the reading of the other event types. See [ROADMAP.md](ROADMAP.md).

## How it fits into the stack

```
CMS (e.g. garlic-hub)  --->  garlic-analytics API  --->  ClickHouse
                                    ^
Player files  --->  Collector  -----+
                    (optional)
```

There are two ways in, both end at the ingest API. The CMS also reads the results from the API.

- The CMS collects the reports and logs of its players, normalizes them and sends them via REST.
- The optional collector reads the files players uploaded, normalizes them with an adapter (currently SMIL) and sends them to the API like any other client.

The API is not public and is reachable only for the CMS and the collector. garlic-analytics is optional. It can run on the same machine as the CMS or on a different one.

## Requirements

- PHP 8.4
- Composer
- ClickHouse 25.8 or newer (for migrations, the integration tests and running the API)

The repository contains a [DDEV](https://ddev.com) setup with PHP and ClickHouse, which is the easiest way to start.

## Getting started

```bash
git clone https://github.com/garlic-signage/garlic-analytics.git
cd garlic-analytics
ddev start
ddev composer install
cp .env.dist .env                 # at least APP_ENV=dev, APP_DEBUG=true and the CLICKHOUSE_* values
ddev exec bin/console db:migrate
ddev exec bin/console apikey:create my-cms --scope=ingest --scope=read
```

The key is shown once. For operation use Docker Compose, see [docs/docker.md](docs/docker.md). Without Docker and DDEV run the same commands directly and point the `CLICKHOUSE_*` settings of `.env` to your ClickHouse.

Run the checks:

```bash
ddev exec vendor/bin/phpstan analyse
ddev exec vendor/bin/phpunit
ddev exec vendor/bin/phpunit -c phpunit.integration.xml   # needs ClickHouse
```

All three must be green. The same checks run on GitHub after every push. Details are in [CONTRIBUTING.md](CONTRIBUTING.md).

## Documentation

- [docs/architecture.md](docs/architecture.md): how the application is put together and how a request runs through it
- [docs/openapi.yaml](docs/openapi.yaml): the API (OpenAPI 3.1), formats and rules of all endpoints
- [docs/cli.md](docs/cli.md): administration commands (`bin/console`), migrations and API keys
- [docs/docker.md](docs/docker.md): operation with Docker Compose (API, ClickHouse and the optional collector)
- [CONTRIBUTING.md](CONTRIBUTING.md): setup, checks and conventions for contributors

## License

AGPL-3.0, see [LICENSE](LICENSE).
 