-- Play logs
-- based on https://garlic-signage.com/garlic-player/docs/essentials/logs-reports/#playlog_format
-- Raw data, kept for two years
CREATE TABLE IF NOT EXISTS play_log
(
    player_id   LowCardinality(String),
    content_id  LowCardinality(String),
    start_time  DateTime('UTC'),
    end_time    DateTime('UTC'),
    duration_s  UInt32 MATERIALIZED toUInt32(greatest(0, dateDiff('second', start_time, end_time))),
    received_at DateTime('UTC') DEFAULT now()
)
    ENGINE = MergeTree
        PARTITION BY toYYYYMM(start_time)
        ORDER BY (player_id, start_time, content_id)
        TTL start_time + INTERVAL 2 YEAR
        SETTINGS
            non_replicated_deduplication_window = 1000000,
            ttl_only_drop_parts = 1;

-- Hourly aggregate, no TTL
-- Always query with sum() and GROUP BY
CREATE TABLE IF NOT EXISTS play_hourly
(
    hour        DateTime('UTC'),
    content_id  LowCardinality(String),
    player_id   LowCardinality(String),
    plays       UInt64,
    duration_s  UInt64
)
    ENGINE = SummingMergeTree((plays, duration_s))
        PARTITION BY toYYYYMM(hour)
        ORDER BY (player_id, hour, content_id)
        SETTINGS
            non_replicated_deduplication_window = 1000000;

-- Fills play_hourly on every insert into play_log
CREATE MATERIALIZED VIEW IF NOT EXISTS play_hourly_mv TO play_hourly AS
SELECT
    toStartOfHour(start_time) AS hour,
    content_id,
    player_id,
    count()         AS plays,
    sum(duration_s) AS duration_s
FROM play_log
GROUP BY hour, content_id, player_id;

-- Player events (playerEventLog records)
-- based on https://garlic-signage.com/garlic-player/docs/essentials/logs-reports/#eventlog_format
-- Kept for six months, no aggregate
CREATE TABLE IF NOT EXISTS event_log
(
    player_id    LowCardinality(String),
    event_time   DateTime('UTC'),
    event_type   Enum8('debug' = 10, 'informational' = 20, 'notice' = 30, 'warning' = 40, 'error' = 50, 'critical' = 60, 'fatal' = 70),
    event_source LowCardinality(String),
    event_name   LowCardinality(String),
    metadata     Map(LowCardinality(String), String),
    received_at  DateTime('UTC') DEFAULT now()
)
    ENGINE = MergeTree
        PARTITION BY toYYYYMM(event_time)
        ORDER BY (player_id, event_time)
        TTL toDateTime(event_time) + INTERVAL 6 MONTH
        SETTINGS
            non_replicated_deduplication_window = 1000000,
            ttl_only_drop_parts = 1;

-- Player connects, one row per index request
-- refresh is the interval of the player in seconds, the time the connect covers
-- Raw data, kept for four years while the data of SmilControl is migrated, afterwards
-- three months (new migration file with ALTER TABLE connect_log MODIFY TTL, and max_age_days in config_connectlog.ini)
CREATE TABLE IF NOT EXISTS connect_log
(
    player_id    LowCardinality(String),
    connected_at DateTime('UTC'),
    refresh      UInt32,
    received_at  DateTime('UTC') DEFAULT now()
)
    ENGINE = MergeTree
        PARTITION BY toYYYYMM(connected_at)
        ORDER BY (player_id, connected_at)
        TTL connected_at + INTERVAL 4 YEAR
        SETTINGS
            non_replicated_deduplication_window = 1000000,
            ttl_only_drop_parts = 1;

-- Hourly aggregate, no TTL
-- covered_s is the sum of refresh, the seconds covered by the connects of that hour
-- Always query with sum() and GROUP BY
CREATE TABLE IF NOT EXISTS connect_hourly
(
    hour      DateTime('UTC'),
    player_id LowCardinality(String),
    connects  UInt64,
    covered_s UInt64
)
    ENGINE = SummingMergeTree((connects, covered_s))
        PARTITION BY toYYYYMM(hour)
        ORDER BY (player_id, hour)
        SETTINGS
            non_replicated_deduplication_window = 1000000;

-- Fills connect_hourly on every insert into connect_log
CREATE MATERIALIZED VIEW IF NOT EXISTS connect_hourly_mv TO connect_hourly AS
SELECT
    toStartOfHour(connected_at) AS hour,
    player_id,
    count()      AS connects,
    sum(refresh) AS covered_s
FROM connect_log
GROUP BY hour, player_id;

-- System reports, one row per report
-- based on https://garlic-signage.com/garlic-player/docs/essentials/logs-reports/#systemreport_format
-- Kept for six months, no aggregate. Not every player reports cpu, memory and hdmi, they stay NULL (hdmi_output empty).
CREATE TABLE IF NOT EXISTS system_log
(
    player_id     LowCardinality(String),
    reported_at   DateTime('UTC') CODEC(Delta, ZSTD),
    system_start  DateTime('UTC'),
    time_zone     LowCardinality(String),
    disk_total    UInt64,
    disk_free     UInt64,
    cpu_usage     Nullable(UInt8),
    memory_total  Nullable(UInt64),
    memory_used   Nullable(UInt64),
    hdmi_output   LowCardinality(String),
    received_at   DateTime('UTC') DEFAULT now()
)
    ENGINE = MergeTree
        PARTITION BY toYYYYMM(reported_at)
        ORDER BY (player_id, reported_at)
        TTL reported_at + INTERVAL 6 MONTH
        SETTINGS
            non_replicated_deduplication_window = 1000000,
            ttl_only_drop_parts = 1;
