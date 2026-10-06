# Roadmap

This roadmap describes the planned direction of garlic-analytics. Each release corresponds to a GitHub milestone, where the individual tasks are tracked.

## 0.1 API core (done)

The foundation: accept normalized events and store them safely.

- Project setup, DDEV with ClickHouse
- Schema with raw event tables, hourly aggregates and TTL
- Migration runner for idempotent schema files
- API key authentication with scopes
- Ingest for PlayLog, EventLog, SystemLog and ConnectLog with validation and idempotency
- `GET /v1/health`
- Collector core: SMIL adapter, file processing with inbox, processed and error handling, `bin/console collector:run`
- OpenAPI description of the ingest API (`docs/openapi.yaml`)
- Gzip request bodies and a size limit for request bodies

## 0.2 Reports (done)

Make the collected data usable.

- Raw play logs of a player in a time range, paginated: `GET /v1/playlog`
- Aggregated proof-of-play of a player: plays and duration per content (`GET /v1/playlog/stats`) and per hour, day or month (`GET /v1/playlog/stats/period`), from `play_hourly`
- Time zone aware queries based on the hourly aggregates, for the proof-of-play and the connects
- Every read endpoint described in `docs/openapi.yaml`

## 0.3 More aggregates (done)

Read the other event types and aggregate for groups of players.

- Raw events of a player in a time range, paginated, optionally filtered by severity (`min_type`, `event_type`), source and name: `GET /v1/eventlog`
- Raw system reports of a player in a time range, paginated: `GET /v1/systemlog`
- Connects of a player per hour, day or month in the time zone of the CMS, from `connect_hourly`: `GET /v1/connectlog`, raw connects for one day: `GET /v1/connectlog/raw`
- Aggregation by player group: the CMS owns the groups and sends the player IDs (at most 1000) in the body of a `POST`, for the play statistics and the connects (`POST /v1/playlog/stats/group`, `/v1/playlog/stats/group/period`, `/v1/connectlog/group`). The route sets the scope `read`.

Open, depends on something outside of the code:

- Set the retention of `connect_log` back to 3 months when the migration of SmilControl is done (new migration file and `max_age_days` in `config_connectlog.ini`)

## 0.4 Collector service

Let players upload directly without a CMS in between.

- Upload via WebDAV and HTTP PUT
- Access management for player uploads, see [below](#upload-access-for-players)
- Docker Compose with API, ClickHouse and the collector as optional profile

## Later

Ideas without a fixed release yet.

- Additional adapters for other player vendors
- Adapter development guide for third parties
- Traffic statistics per player, e.g. for bandwidth quotas

## Upload access for players

Planned for 0.4. Not urgent: until then the CMS selects the players itself and sends the data to the API, no player uploads directly.

**Decision**

- The CMS owns a list of the player UUIDs that may upload, analytics enforces it. Anyone can upload, the list decides what is accepted. No tokens, no rotating passwords, no directories per player.
- The player UUID is unique and random, so it cannot be guessed. It is not authentication, whoever knows a listed UUID can submit data for it. This is accepted.

**Checks**

1. At the door (optional, cheap): the player sends its UUID in the `User-Agent`. The web server looks it up in the list and refuses unknown ones before anything is stored (Apache: `RewriteMap` on a text file, read on every request; nginx: `map`, needs a reload). The `User-Agent` can be set by anyone, so this only keeps out bots and strangers, it is no proof of identity.
2. In the collector (always): the UUID in `<player id="...">` must be on the list. If not, the whole file goes to `error/`, like any broken file.

**Protection of the server**

- Size limit per upload and rate limit per IP at the web server, so nobody can fill the disk. This is the most important one, the upload is open.
- `error/` is cleaned after some days, otherwise it fills up with files of unknown players.
- The collector parses anonymous files, so the parser needs limits for size and time (`XMLReader` with `LIBXML_NONET` is in place, the limits are to be checked).
- The texts of a report (`event_name`, `event_source`, `metadata`, `time_zone`, `hdmi_output`) come from the player and are shown in the CMS. The CMS must escape them.

**Managing the list**

- CLI `bin/console player:add|list|remove`, like `apikey:*`.
- Later optionally an API for the CMS (`PUT`/`DELETE`/`GET /v1/uploaders`). It needs a management scope: a new case in `Scope`, set on the routes with `->setArgument('scope', 'manage')`, which `ApiKeyMiddleware` already reads.

**To decide before the work starts**

1. Where the list lives and how the web server reads it. One text file that the collector and the web server both read is the simplest.
2. The exact format of the `User-Agent` of an upload (the UUID is in it), best from a capture of a real upload.
3. The limits: file size and rate per IP, days until `error/` is cleaned.
