# Command line

Administration runs through one entry point, `bin/console` (built on `symfony/console`). It only works from the command line.

```bash
bin/console list                  # all commands
bin/console <command> --help      # arguments and options of one command
```

In the DDEV development environment, prefix every call with `ddev exec`:

```bash
ddev exec bin/console list
```

All commands return exit code `0` on success and `1` on failure, so they can be used in scripts. Error messages are written to stderr where the command has one.

| Command | Purpose |
|---|---|
| [`db:migrate`](#dbmigrate) | Apply the schema files to ClickHouse |
| [`apikey:create`](#apikeycreate) | Create an API key for a client |
| [`apikey:list`](#apikeylist) | List clients and their scopes |
| [`apikey:revoke`](#apikeyrevoke) | Delete the API key of a client |
| [`collector:run`](#collectorrun) | Send uploaded device files to the ingest API |

## db:migrate

```bash
bin/console db:migrate
```

Runs all `migrations/*.sql` files in file name order and prints `executed: <file>` for each one. The files are executed on every run, so all statements are idempotent (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`). Running the command repeatedly is safe, and new tables and columns reach existing installations the same way.

On failure (for example ClickHouse is not reachable) it prints `Migration failed: <message>` and stops with exit code `1`. Files that ran before the failing statement stay applied.

The connection is taken from the `CLICKHOUSE_*` variables in `.env`, see `.env.dist`. Rules for writing schema files are in [CONTRIBUTING.md](../CONTRIBUTING.md#database-schema).

## apikey:create

```bash
bin/console apikey:create <name> --scope=<scope> [--scope=<scope> ...]
```

| | |
|---|---|
| `name` | Unique name of the client, for example `garlic-hub` or `collector`. Letters, digits, `.`, `_` and `-`, at most 64 characters. |
| `--scope`, `-s` | What the key may do. Required, can be repeated. |

Scopes:

- `ingest`: write requests (`POST`, `PUT`, `DELETE`). The collector only gets this one.
- `read`: read requests (`GET`, `HEAD`).

Example:

```bash
bin/console apikey:create garlic-hub --scope=ingest --scope=read
```

```
API key for garlic-hub (ingest, read):
3f9c…64 hex characters…a1
Store it now, it cannot be shown again.
```

The key is printed once. Only its SHA-256 hash is stored, so a lost key cannot be recovered. Create a new one instead. The client sends the key as `Authorization: Bearer <key>`.

The command fails without changing anything if the name is invalid or already taken, a scope is unknown, or no scope is given.

## apikey:list

```bash
bin/console apikey:list
```

Shows every client that has a key and its scopes:

```
+------------+--------------+
| Name       | Scopes       |
+------------+--------------+
| garlic-hub | ingest, read |
| collector  | ingest       |
+------------+--------------+
```

The keys themselves are not stored and therefore not shown. Prints `No API keys.` if there are none.

## apikey:revoke

```bash
bin/console apikey:revoke <name>
```

Deletes the key of the named client. Requests with that key get `401` from then on. Fails with exit code `1` if no key has that name.

To rotate a key without downtime, create a new key under a different name, switch the client over, then revoke the old one.

## Where the keys are stored

`var/keys/api_keys.json`, outside the docroot and not part of the repository (`/var/` is ignored by Git). The file maps each client name to its hash and scopes:

```json
{
    "garlic-hub": {
        "hash": "<sha256 of the key>",
        "scopes": ["ingest", "read"]
    }
}
```

It is written atomically with mode `0600`, its directory has mode `0700`. In a container setup, `var/` should be a volume so the keys survive a restart. Back it up like any other secret: it contains no usable keys, but losing it invalidates all of them.

See [Authentication in docs/architecture.md](architecture.md#authentication) for how the keys are checked on a request.

## collector:run

```bash
bin/console collector:run [--device=<name>] [--type=<type>] [--file=<name>] [--dry-run]
```

Reads the files devices uploaded, sends them to the ingest API and moves them. Run it from cron, the collector is not an API itself.

Every device family has its own upload directory, `var/collector/<device>/upload/`. The directory decides which adapter reads a file, the adapter decides the type by the file name. For `smil` these are `playlog-*.xml`, `event-*.xml` and `system-*.xml`. Only play logs are sent so far. Files of other types and files with an unknown name stay in `upload/`.

| Option | |
|---|---|
| `--device` | Only this device, e.g. `smil`. |
| `--type` | Only this type: `playlog`, `event` or `system`. |
| `--file` | Only the file with this name. Also read if it is very new. |
| `--dry-run` | Read and count, send and move nothing. Needs no API key. |

**Settings.** The API is configured in `.env`: `COLLECTOR_API_URL` (default `http://localhost`) and `COLLECTOR_API_KEY`. Create the key with `bin/console apikey:create collector --scope=ingest`. Without a key the command stops before it touches any file. `batch_size`, `min_age_seconds` and the timeouts are in `config/settings/config_collector.ini`. Files changed during the last `min_age_seconds` are skipped because they may still be uploading.

**What happens to a file.** The events are cut into blocks of `batch_size`, one request per block. The cut is always the same for the same file, so blocks the API already has are dropped as duplicates when a file is sent again.

| Result | What happens |
|---|---|
| All blocks accepted (`2xx`) | File moves to `processed/`. A file without events moves too, without a request. |
| File is not valid XML, or the API answers `400`, `413` or `422` | File moves to `error/`, with `<file>.error` (time and reason) next to it. |
| API not reachable, `5xx`, `408`, `429`, or `401`, `403`, `404`, `405` (wrong key, rights or URL) | File stays in `upload/` and the run stops, the next run starts again with it. |

`processed/` and `error/` are next to `upload/`. If a name is already taken there, a time stamp is put before the extension. To process a file again, move it back to `upload/`.

Only one run works at a time (`var/collector/collector.lock`). If another run is active, the command ends with exit code `0`.

**Exit code.** `1` if the run had to stop or the settings are incomplete, otherwise `0`. Files in `error/` are a data problem and do not change the exit code, so look into `error/` from time to time.
