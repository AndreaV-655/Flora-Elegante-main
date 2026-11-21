"""Conexiones cortas y auditoría sin credenciales ni texto de imágenes."""
import json
import os
from datetime import datetime
from zoneinfo import ZoneInfo

import psycopg
from psycopg.rows import dict_row


def now():
    return datetime.now(ZoneInfo(os.getenv('TIMEZONE', 'America/Mexico_City'))).replace(tzinfo=None)


def connect():
    params = dict(
        host=os.getenv('DB_HOST', '127.0.0.1'),
        port=int(os.getenv('DB_PORT', '5432')),
        dbname=os.getenv('DB_NAME', 'postgres'),
        user=os.getenv('DB_USER', 'postgres'),
        password=os.getenv('DB_PASS', ''),
        connect_timeout=10,
        row_factory=dict_row,
        autocommit=False,
    )
    if os.getenv('DB_SSL', '0') == '1':
        params['sslmode'] = os.getenv('DB_SSL_MODE', 'require')
        if os.getenv('DB_SSL_CA'):
            params['sslrootcert'] = os.getenv('DB_SSL_CA')
    conn = psycopg.connect(**params)
    with conn.cursor() as cur:
        cur.execute('SELECT set_config(%s, %s, false)', ('TimeZone', os.getenv('TIMEZONE', 'America/Mexico_City')))
    return conn


def audit(cur, action, reference, result):
    cur.execute('INSERT INTO fe_audit (accion, referencia, resultado) VALUES (%s,%s,%s)',
                (action, str(reference)[:190], str(result)[:200]))


def enqueue(cur, key, channel, payload):
    cur.execute('INSERT INTO fe_job (clave, canal, payload) VALUES (%s,%s,%s) ON CONFLICT (clave) DO NOTHING',
                (key, channel, json.dumps(payload, ensure_ascii=False, default=str)))
