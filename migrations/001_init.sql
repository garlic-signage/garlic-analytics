-- Play logs
-- based on https://garlic-signage.com/garlic-player/docs/essentials/logs-reports/#playlog_format
-- Raw data, kept for two years
CREATE TABLE IF NOT EXISTS play_log
(
    player_id   LowCardinality(String),
    display_id  LowCardinality(String),
    content_id  LowCardinality(String),
    start_time  DateTime64(3, 'UTC'),
    end_time    DateTime64(3, 'UTC'),
    duration_ms UInt32 MATERIALIZED toUInt32(greatest(0, dateDiff('millisecond', start_time, end_time))),
    report_id   UUID,
    received_at DateTime('UTC') DEFAULT now()
)
    ENGINE = MergeTree
        PARTITION BY toYYYYMM(start_time)
        ORDER BY (player_id, start_time, content_id)
        TTL toDateTime(start_time) + INTERVAL 2 YEAR
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
    display_id  LowCardinality(String),
    plays       UInt64,
    duration_ms UInt64
)
    ENGINE = SummingMergeTree((plays, duration_ms))
        PARTITION BY toYYYYMM(hour)
        ORDER BY (content_id, hour, player_id, display_id);

-- Fills play_hourly on every insert into play_log
CREATE MATERIALIZED VIEW IF NOT EXISTS play_hourly_mv TO play_hourly AS
SELECT
    toStartOfHour(start_time) AS hour,
    content_id,
    player_id,
    display_id,
    count()          AS plays,
    sum(duration_ms) AS duration_ms
FROM play_log
GROUP BY hour, content_id, player_id, display_id;

-- Player events (playerEventLog records)
-- based on https://garlic-signage.com/garlic-player/docs/essentials/logs-reports/#eventlog_format
-- Kept for six months, no aggregate
CREATE TABLE IF NOT EXISTS player_event
(
    player_id    LowCardinality(String),
    event_time   DateTime64(3, 'UTC'),
    event_type   Enum8('debug' = 10, 'informational' = 20, 'notice' = 30, 'warning' = 40, 'error' = 50, 'critical' = 60, 'fatal' = 70),
    event_source LowCardinality(String),
    event_name   LowCardinality(String),
    metadata     Map(LowCardinality(String), String),
    report_id    UUID,
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
-- Raw data, kept for three months
CREATE TABLE IF NOT EXISTS player_connect
(
    player_id    LowCardinality(String),
    connected_at DateTime('UTC'),
    refresh      UInt32
)
    ENGINE = MergeTree
        PARTITION BY toYYYYMM(connected_at)
        ORDER BY (player_id, connected_at)
        TTL connected_at + INTERVAL 3 MONTH
        SETTINGS ttl_only_drop_parts = 1;

-- Hourly aggregate, no TTL
-- covered_s is the sum of refresh, the seconds covered by the connects of that hour
-- Always query with sum() and GROUP BY
CREATE TABLE IF NOT EXISTS player_connect_hourly
(
    hour      DateTime('UTC'),
    player_id LowCardinality(String),
    connects  UInt64,
    covered_s UInt64
)
    ENGINE = SummingMergeTree((connects, covered_s))
        PARTITION BY toYYYYMM(hour)
        ORDER BY (player_id, hour);

-- Fills player_connect_hourly on every insert into player_connect
CREATE MATERIALIZED VIEW IF NOT EXISTS player_connect_hourly_mv TO player_connect_hourly AS
SELECT
    toStartOfHour(connected_at) AS hour,
    player_id,
    count()      AS connects,
    sum(refresh) AS covered_s
FROM player_connect
GROUP BY hour, player_id;

--- history of System reports
-- based on https://garlic-signage.com/garlic-player/docs/essentials/logs-reports/#systemreport_format
CREATE TABLE IF NOT EXISTS  system_report
(
    player_id     LowCardinality(String),
    reported_at   DateTime('UTC') CODEC(Delta, ZSTD),
    system_start  DateTime('UTC'),
    time_zone     LowCardinality(String),
    disk_total    UInt64,
    disk_free     UInt64,
    cpu_usage     UInt8,
    memory_total  UInt64,
    memory_used   UInt64,
    hdmi_output   LowCardinality(String),
    temperature   Nullable(Float32),
    extra         Map(LowCardinality(String), Float64)
)
    ENGINE = MergeTree
        PARTITION BY toYYYYMM(reported_at)
        ORDER BY (player_id, reported_at)
        TTL reported_at + INTERVAL 6 MONTH
        SETTINGS
            non_replicated_deduplication_window = 1000000,
            ttl_only_drop_parts = 1;
