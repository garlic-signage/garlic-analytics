# garlic-analytics

[![PHPUnit](https://github.com/garlic-signage/garlic-analytics/actions/workflows/phpunit.yml/badge.svg?branch=main)](https://github.com/garlic-signage/garlic-analytics/actions/workflows/phpunit.yml)
[![PHPStan](https://github.com/garlic-signage/garlic-analytics/actions/workflows/phpstan.yml/badge.svg?branch=main)](https://github.com/garlic-signage/garlic-analytics/actions/workflows/phpstan.yml)
![PHPStan Level](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)

Self-hosted analytics service for digital signage players. Collects logs, proof-of-play reports and connection events in ClickHouse and provides a REST API for CMS integration.

Part of the [GarlicSignage](https://github.com/garlic-signage) stack.

## Status

Early development. The base is in place: configuration, error handling, tests and CI. Ingest and query endpoints are not implemented yet.

## How it fits into the stack

```
Player  --->  CMS (e.g. garlic-hub)  --->  garlic-analytics  --->  ClickHouse
                      ^                          |
                      +-------- queries ---------+
```

Players never talk to garlic-analytics directly. The CMS collects their reports and logs, forwards them via REST and asks garlic-analytics for evaluations.

garlic-analytics is optional. It can run on the same machine as the CMS or on a different one.

## Requirements

- PHP 8.4
- Composer
- ClickHouse (not needed yet for development)

## Getting started

```bash
git clone https://github.com/garlic-signage/garlic-analytics.git
cd garlic-analytics
composer install
```

Create a `.env` file in the project root:

```
APP_ENV=dev
APP_DEBUG=true
```

Run the checks:

```bash
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

Both must be green. The same two commands run on GitHub after every push.

## Documentation

- [docs/architecture.md](docs/architecture.md): how the application is put together and how a request runs through it
- [docs/cli.md](docs/cli.md): administration commands (`bin/console`), migrations and API keys
- [CONTRIBUTING.md](CONTRIBUTING.md): setup, checks and conventions for contributors

## License

AGPL-3.0, see [LICENSE](LICENSE).
 