CREATE DATABASE IF NOT EXISTS bionicpro;

-- Витрина отчётов
CREATE TABLE IF NOT EXISTS bionicpro.report_by_user (
    keycloak_id    String,
    first_name     String,
    last_name      String,
    email          String,
    serial_number  String,
    issued_at      Date,
    signal_type    String,
    avg_signal     Float64,
    signal_count   UInt32,
    last_recorded  DateTime,
    report_date    Date
)
ENGINE = ReplacingMergeTree()
ORDER BY (keycloak_id, signal_type, report_date);

-- Метка последнего успешного запуска ETL
CREATE TABLE IF NOT EXISTS bionicpro.etl_watermark (
    dag_id    String,
    last_run  DateTime
)
ENGINE = ReplacingMergeTree()
ORDER BY dag_id;

-- ─────────────────────────────────────────────────────────────────────────────
-- CDC: CRM clients (Debezium → Kafka → ClickHouse)
-- ─────────────────────────────────────────────────────────────────────────────

-- Актуальное состояние клиентов CRM
CREATE TABLE IF NOT EXISTS bionicpro.crm_clients (
    keycloak_id   String,
    first_name    String,
    last_name     String,
    email         String,
    serial_number String,
    issued_at     Date,
    is_deleted    UInt8    DEFAULT 0,
    updated_at    DateTime DEFAULT now()
)
ENGINE = ReplacingMergeTree(updated_at)
ORDER BY keycloak_id;

-- Kafka engine — читает сырые CDC-события от Debezium
-- issued_at приходит как int (дни с 1970-01-01), __deleted — как bool (0/1)
CREATE TABLE IF NOT EXISTS bionicpro.crm_clients_queue (
    id            Int32,
    keycloak_id   String,
    first_name    String,
    last_name     String,
    email         String,
    serial_number String,
    issued_at     Int32,
    created_at    Int64,
    __deleted     UInt8
)
ENGINE = Kafka
SETTINGS
    kafka_broker_list = 'kafka:9092',
    kafka_topic_list  = 'crm.public.clients',
    kafka_group_name  = 'clickhouse_crm',
    kafka_format      = 'JSONEachRow',
    kafka_skip_broken_messages = 1;

-- MV: перекладывает новые события из очереди в постоянную таблицу
CREATE MATERIALIZED VIEW IF NOT EXISTS bionicpro.crm_clients_mv
TO bionicpro.crm_clients AS
SELECT
    keycloak_id,
    first_name,
    last_name,
    email,
    serial_number,
    toDate(issued_at) AS issued_at,
    __deleted         AS is_deleted,
    now()             AS updated_at
FROM bionicpro.crm_clients_queue
WHERE keycloak_id != '';

-- ─────────────────────────────────────────────────────────────────────────────
-- Агрегированная телеметрия (пишет Airflow, только телеметрия без CRM-данных)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bionicpro.telemetry_agg (
    keycloak_id   String,
    signal_type   String,
    avg_signal    Float64,
    signal_count  UInt32,
    last_recorded DateTime,
    report_date   Date
)
ENGINE = ReplacingMergeTree()
ORDER BY (keycloak_id, signal_type, report_date);

-- ─────────────────────────────────────────────────────────────────────────────
-- Новая витрина: JOIN crm_clients + telemetry_agg через MaterializedView
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bionicpro.report_by_user_v2 (
    keycloak_id   String,
    first_name    String,
    last_name     String,
    email         String,
    serial_number String,
    issued_at     Date,
    signal_type   String,
    avg_signal    Float64,
    signal_count  UInt32,
    last_recorded DateTime,
    report_date   Date
)
ENGINE = ReplacingMergeTree()
ORDER BY (keycloak_id, signal_type, report_date);

-- MV: при каждой вставке телеметрии обогащает данные CRM-данными из crm_clients
CREATE MATERIALIZED VIEW IF NOT EXISTS bionicpro.report_mv
TO bionicpro.report_by_user_v2 AS
SELECT
    t.keycloak_id,
    c.first_name,
    c.last_name,
    c.email,
    c.serial_number,
    c.issued_at,
    t.signal_type,
    t.avg_signal,
    t.signal_count,
    t.last_recorded,
    t.report_date
FROM bionicpro.telemetry_agg AS t
LEFT JOIN (
    SELECT keycloak_id, first_name, last_name, email, serial_number, issued_at
    FROM bionicpro.crm_clients FINAL
    WHERE is_deleted = 0
) AS c ON t.keycloak_id = c.keycloak_id;
