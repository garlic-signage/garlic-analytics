# Roadmap

This roadmap describes the planned direction of garlic-analytics. Each release corresponds to a GitHub milestone, where the individual tasks are tracked.

## 0.1 API core

The foundation: accept normalized proof-of-play events and store them safely.

- Project setup, Docker Compose with ClickHouse and API
- Event format for proof-of-play
- Schema with raw event table, hourly aggregates and TTL
- Migration runner for idempotent schema files
- API key authentication
- First module PlayLog: ingest via `POST /v1/playlog` with validation and idempotency
- `GET /v1/health`

## 0.2 Reports

Make the collected data usable.

- Read endpoints for proof-of-play (plays and duration per player, media and time range, paginated)
- Time zone aware queries based on hourly aggregates
- API documentation

## 0.3 More event types

Extend beyond proof-of-play.

- Player logs
- Player connection events
- CMS error messages
- Aggregation by player group

## 0.4 Collector

Let players upload directly without a CMS in between.

- Collector container with WebDAV and HTTP PUT upload
- Adapter interface
- First adapter for SMIL player reports
- File processing with inbox, processed and error handling
- Optional start via Docker Compose profile

## Later

Ideas without a fixed release yet.

- Additional adapters for other player vendors
- Adapter development guide for third parties
- Traffic statistics per player, e.g. for bandwidth quotas
