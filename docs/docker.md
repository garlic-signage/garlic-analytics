# Operation with Docker

`compose.yaml` runs the application the way it is meant for operation: Apache in front of PHP-FPM (like the apache-fpm web server of the DDEV setup, but in two containers) and ClickHouse. For development use DDEV, see the [README](../README.md#getting-started).

| Service | What it is |
|---|---|
| `clickhouse` | `clickhouse/clickhouse-server:25.8`, data in the volume `clickhouse-data`. Not published, only the other containers reach it. |
| `migrate` | Applies the schema files (`bin/console db:migrate`) on every start and exits. The other services wait for it. |
| `php` | The application (`Dockerfile`): PHP-FPM with the code and the production dependencies. Keys, logs and the files of the collector are in the volume `analytics-var`. |
| `web` | Apache (`docker/apache/httpd.conf`). It has no code, every request goes to `public/index.php` of the `php` container. Published on `127.0.0.1:8070`. |
| `collector` | Optional, profile `collector`. Runs `bin/console collector:run` every `COLLECTOR_INTERVAL` seconds. |

## Settings

Compose reads the file `.env` **in the project directory**. Create it on the server, the values go into the containers as environment variables (the image has no `.env` and no secrets).

| Variable | Default | |
|---|---|---|
| `APP_SECRET` | | **required**, a random string of 32 characters |
| `CLICKHOUSE_PASSWORD` | | **required** |
| `CLICKHOUSE_USER` | `analytics` | created by the ClickHouse image |
| `CLICKHOUSE_DATABASE` | `analytics` | created by the ClickHouse image |
| `MAX_BODY_BYTES` | `8388608` | limit of a request body, see the [OpenAPI description](openapi.yaml) |
| `API_BIND` | `127.0.0.1:8080` | address and port the API is published on |
| `COLLECTOR_API_KEY` | | key of the collector, scope `ingest` |
| `COLLECTOR_INTERVAL` | `60` | seconds between two runs of the collector |

A checkout that is also used for development has a `.env` for DDEV (with `CLICKHOUSE_USER=default` and the like). Compose reads that one too and it wins over the defaults above. Set the variables in the shell or use `docker compose --env-file <file>` there.

## Start

```bash
docker compose up -d --build
curl http://127.0.0.1:8080/v1/health          # {"status":"ok"}
```

Create the API keys. Always with `-u www-data`: a file that `root` creates in `var/keys` cannot be read by the PHP processes.

```bash
docker compose exec -u www-data php php bin/console apikey:create cms --scope=read --scope=ingest
docker compose exec -u www-data php php bin/console apikey:list
```

A key is shown once, see [cli.md](cli.md#apikeycreate).

## Collector

```bash
docker compose exec -u www-data php php bin/console apikey:create collector --scope=ingest
# put the key into .env as COLLECTOR_API_KEY, then
docker compose --profile collector up -d
```

The collector reads `var/collector/smil/upload/` in the volume `analytics-var` and moves the files to `processed/` or `error/` next to it, see [cli.md](cli.md#collectorrun). There is no upload server yet (see the [roadmap](../ROADMAP.md)). Until then the files must get into that directory by other means, for example `docker compose cp file.xml collector:/var/www/html/var/collector/smil/upload/`. A file is processed when it has not changed for `min_age_seconds` (60).

## TLS and access

The API is not public, only the CMS and the collector use it. By default it listens on `127.0.0.1` only. Put a reverse proxy with TLS in front of it, or set `API_BIND=0.0.0.0:8080` if the CMS is on another host and the port is protected by other means. Apache does not do TLS.

## Data, updates, logs

- `docker compose down` keeps the data, `docker compose down -v` **deletes** it (ClickHouse data and `analytics-var`).
- Back up both volumes. `analytics-var` holds the hashes of the API keys, treat it like a secret. Losing it invalidates all keys.
- Update: `git pull && docker compose up -d --build`. The schema changes are applied by `migrate`. The ClickHouse image is pinned, a new version is a deliberate change of `compose.yaml`.
- Logs: `docker compose logs -f web php collector`. The application writes its 5xx errors to `var/logs/` in the volume.
- The code of the image is never checked again for changes (OPcache), a new version needs a new build.
