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
    description='ETL: CRM + телеметрия → ClickHouse витрина',
    schedule='@daily',
    start_date=datetime(2024, 1, 1),
    catchup=False,
) as dag:

    @task
    def extract_from_crm():
        conn = psycopg2.connect(os.environ['CRM_POSTGRES_CONN'])
        cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        cursor.execute("""
            SELECT keycloak_id, first_name, last_name,
                   email, serial_number, issued_at
            FROM clients
            WHERE keycloak_id IS NOT NULL
        """)
        rows = [dict(row) for row in cursor.fetchall()]
        cursor.close()
        conn.close()
        return rows

    @task
    def extract_from_bionicpro():
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
    def transform(crm_data: list, telemetry_data: list) -> list:
        crm_by_id = {row['keycloak_id']: row for row in crm_data}
        today = datetime.now().date()
        result = []

        for t in telemetry_data:
            kid = t['keycloak_id']
            if kid not in crm_by_id:
                continue

            client = crm_by_id[kid]
            result.append({
                'keycloak_id':   kid,
                'first_name':    client['first_name'],
                'last_name':     client['last_name'],
                'email':         client['email'],
                'serial_number': client['serial_number'],
                'issued_at':     client['issued_at'],
                'signal_type':   t['signal_type'],
                'avg_signal':    float(t['avg_signal']),
                'signal_count':  int(t['signal_count']),
                'last_recorded': t['last_recorded'],
                'report_date':   today,
            })

        print(f"Трансформировано строк: {len(result)}")
        return result

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

        ch.execute('INSERT INTO report_by_user VALUES', data)

        ch.execute(
            'INSERT INTO etl_watermark VALUES',
            [{'dag_id': 'bionicpro_reports_etl', 'last_run': datetime.now()}]
        )

        print(f"Загружено строк: {len(data)}")

    # ── порядок выполнения ──────────────────────────────────
    crm_data       = extract_from_crm()
    telemetry_data = extract_from_bionicpro()
    transformed    = transform(crm_data, telemetry_data)
    load_to_clickhouse(transformed)