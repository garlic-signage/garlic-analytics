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

## 0.2 Reports

Make the collected data usable.

- Raw play logs of a player in a time range, paginated: `GET /v1/playlog` (done)
- Aggregated proof-of-play (plays and duration per player, media and time range, paginated)
- Time zone aware queries based on hourly aggregates (done for the connects, still to do for the proof-of-play)
- Every new read endpoint described in `docs/openapi.yaml`

## 0.3 More aggregates

Read the other event types.

- Raw events of a player in a time range, paginated, optionally filtered by severity (`min_type`, `event_type`), source and name: `GET /v1/eventlog` (done)
- Raw system reports of a player in a time range, paginated: `GET /v1/systemlog` (done)
- Connects of a player per hour, day or month in the time zone of the CMS, from `connect_hourly`: `GET /v1/connectlog` (done), raw connects for one day: `GET /v1/connectlog/raw` (done)
- Aggregation by player group
- Set the retention of `connect_log` back to 3 months when the migration of SmilControl is done

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

Planned for 0.4, not decided yet. Players upload with HTTP Basic Auth over HTTPS. The CMS administers who may upload, analytics enforces it.

**Idea**

- One credential per player (the player ID plus a random password), never a shared one. If an SMIL index leaks, only uploads for that one player are affected and the credential can be revoked alone.
- A credential only allows writing (`PUT`) into the own directory, no reading or listing.
- The player ID comes from the authenticated user, never from the content of the file. Otherwise a valid player could report data for another player.
- The password is shown once on creation, analytics stores only a hash (like API keys). If the CMS loses it, the credential is rotated.

**API (new module `Uploader`, own scope e.g. `manage`)**

- `PUT /v1/uploaders/{player_id}`: create or rotate, returns the password once
- `DELETE /v1/uploaders/{player_id}`: revoke
- `GET /v1/uploaders`: list the allowed players, without passwords
- CLI: `bin/console player:add|list|remove` for the same, like `apikey:*`

**To decide before the work starts**

1. How does the upload endpoint learn the credentials? Either a volume with an auth file (e.g. Apache DBM) shared with the API, or the collector checks Basic Auth itself against a store the API writes. The second is cleaner and independent of the web server configuration, but PHP then accepts the upload instead of a ready-made WebDAV server, which is a larger change than `docs/architecture.md` describes.
2. Scopes: `Scope::forMethod` derives the scope from the HTTP method, so `PUT` and `DELETE` count as `ingest` today. A management scope needs a rule by route, otherwise the collector's `ingest` key could enable players.
3. Who creates the password: analytics (returns it to the CMS) or the CMS (registers it at analytics).
4. Size limits per upload at the web server, so an unknown or malicious client cannot fill the disk.
5. Whether the SMIL player can send Basic Auth credentials for uploads, and where the player reads them from.

