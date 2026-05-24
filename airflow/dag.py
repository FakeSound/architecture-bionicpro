from airflow import DAG
from airflow.decorators import task
from datetime import datetime, timedelta
import os
import psycopg2
import psycopg2.extras
from clickhouse_driver import Client as ClickHouseClient


default_args = {
    'owner': 'bionicpro',
    'retries': 1,
    'retry_delay': timedelta(minutes=5),
}

with DAG(
    dag_id='bionicpro_reports_etl',
    default_args=default_args,
    description='ETL: телеметрия → ClickHouse (CRM-данные поступают через CDC)',
    schedule='@daily',
    start_date=datetime(2024, 1, 1),
    catchup=False,
) as dag:

    @task
    def extract_telemetry():
        conn = psycopg2.connect(os.environ['BIONICPRO_POSTGRES_CONN'])
        cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        cursor.execute("""
            SELECT
                keycloak_id,
                signal_type,
                ROUND(AVG(signal_value)::numeric, 4) AS avg_signal,
                COUNT(*)                              AS signal_count,
                MAX(recorded_at)                      AS last_recorded
            FROM telemetry
            GROUP BY keycloak_id, signal_type
        """)
        rows = [dict(row) for row in cursor.fetchall()]
        cursor.close()
        conn.close()
        return rows

    @task
    def load_to_clickhouse(data: list):
        if not data:
            print("Нет данных для загрузки")
            return

        ch = ClickHouseClient(
            host=os.environ['CLICKHOUSE_HOST'],
            port=int(os.environ['CLICKHOUSE_PORT']),
            database='bionicpro',
        )

        today = datetime.now().date()
        rows = [{**row, 'report_date': today} for row in data]

        # Записываем агрегированную телеметрию; MV report_mv сделает JOIN с crm_clients
        ch.execute('INSERT INTO telemetry_agg VALUES', rows)

        ch.execute(
            'INSERT INTO etl_watermark VALUES',
            [{'dag_id': 'bionicpro_reports_etl', 'last_run': datetime.now()}]
        )

        print(f"Загружено строк: {len(rows)}")

    telemetry_data = extract_telemetry()
    load_to_clickhouse(telemetry_data)
