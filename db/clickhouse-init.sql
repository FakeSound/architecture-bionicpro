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
