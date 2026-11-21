"""Motor de automatización. Ejecutar continuamente: python worker.py.

Un lock advisory de PostgreSQL evita dos workers enviando simultáneamente. Cada canal tiene
su propio trabajo, reintentos exponenciales y auditoría. Entrega al menos una
vez: si el proveedor recibe y el proceso muere antes de confirmar, puede haber
duplicado. El ID estable del pedido permite identificarlo; no se promete exactly-once.
"""
import json
import os
import secrets
import smtplib
import ssl
import time
import urllib.request
from datetime import timedelta, date
from email.message import EmailMessage
from core import anniversary, previous_month, financial_summary
from storage import connect, now, enqueue, audit


def telegram(text):
    token, chat = os.getenv('TELEGRAM_BOT_TOKEN'), os.getenv('TELEGRAM_CHAT_ID')
    if not token or not chat:
        raise ValueError('Telegram no configurado')
    request = urllib.request.Request('https://api.telegram.org/bot' + token + '/sendMessage',
        data=json.dumps({'chat_id': chat, 'text': text[:4000]}).encode(),
        headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(request, timeout=20) as response:
        if not json.load(response).get('ok'):
            raise RuntimeError('Telegram rechazó el mensaje')


def email(to, subject, text, key):
    host = os.getenv('SMTP_HOST')
    if not host or not os.getenv('SMTP_FROM'):
        raise ValueError('SMTP no configurado')
    msg = EmailMessage()
    msg['From'], msg['To'], msg['Subject'] = os.environ['SMTP_FROM'], to, subject
    msg['Message-ID'] = '<' + key.replace(':', '-') + '@flora-elegante.local>'
    msg.set_content(text)
    port = int(os.getenv('SMTP_PORT', '587'))
    context = ssl.create_default_context()
    client = smtplib.SMTP_SSL(host, port, timeout=20, context=context) if port == 465 else smtplib.SMTP(host, port, timeout=20)
    with client:
        if port != 465:
            client.starttls(context=context)
        if os.getenv('SMTP_USER'):
            client.login(os.environ['SMTP_USER'], os.environ['SMTP_PASSWORD'])
        client.send_message(msg)


def deliver(job):
    p = json.loads(job['payload'])
    channel = job['canal']
    if channel.startswith('pedido_'):
        order, customer = p['pedido'], p['cliente']
        lines = '\n'.join(f"{i['cantidad']} x {i['nombre']}: COP {i['subtotal']}" for i in p['items'])
        receipt = (f"Recibo {order['codigo']} - Pedido {order['id']}\n{lines}\n"
                   f"Descuento: COP {order.get('descuento', 0)}\nTotal: COP {order['total']}\n"
                   f"Entrega: {order['entrega']}\nDirección: {customer['direccion']}\n"
                   'Comprobante comercial de pedido; no acredita pago ni es factura electrónica tributaria.')
        if channel == 'pedido_email':
            email(customer['email'], 'Tu recibo de Flora Elegante', receipt, job['clave'])
        else:
            telegram(receipt + f"\nCliente: {customer['nombre']}\nTeléfono: {customer.get('telefono') or 'Sin teléfono'}\nDedicatoria: {order.get('dedicatoria') or '-'}")
    elif channel == 'cupon_email':
        email(p['email'], 'Se acerca una fecha especial',
              f"Tu cupón personal es {p['codigo']}: {p['porcentaje']}% de descuento.\n"
              f"Válido hasta {p['vence']}, un solo uso. Ingrésalo en el carrito o en el chat antes de comprar.", job['clave'])
    elif channel == 'telegram':
        telegram(p['texto'])
    else:
        raise ValueError('Canal desconocido')


def scheduled(cur, today):
    # Ejecutar varias veces el mismo día es seguro: las claves únicas deduplican.
    threshold = int(os.getenv('STOCK_THRESHOLD', '5'))
    cur.execute("SELECT id,nombre,stock FROM producto WHERE estado='activo'")
    for product in cur.fetchall():
        key = 'stock:' + str(product['id'])
        cur.execute('SELECT valor FROM fe_state WHERE clave=%s', (key,))
        state = cur.fetchone()
        # Se rearma al reponer; no manda la misma alerta cada minuto.
        low = product['stock'] <= threshold
        if low and (not state or state['valor'] == 'alto'):
            enqueue(cur, key + ':' + secrets.token_hex(8), 'telegram',
                    {'texto': f"Stock bajo: {product['nombre']} ({product['stock']} unidades). Umbral: {threshold}."})
        cur.execute('INSERT INTO fe_state (clave,valor) VALUES (%s,%s) ON CONFLICT (clave) DO UPDATE SET valor=EXCLUDED.valor', (key, 'bajo' if low else 'alto'))

    cur.execute("""SELECT pe.id,pe.cliente_id,COALESCE(pe.fecha_evento,pe.fecha) AS evento,u.email
                   FROM pedido pe JOIN usuario u ON u.id=pe.cliente_id
                   JOIN cliente_perfil cp ON cp.usuario_id=u.id
                   WHERE pe.estado <> 'Cancelado' AND u.activo=1 AND cp.marketing=1""")
    for order in cur.fetchall():
        source = order['evento'].date()
        if source >= today:
            continue
        target = anniversary(source, today, int(os.getenv('REMINDER_DAYS', '7')))
        if not target or target.year <= source.year:
            continue
        # Un cupón por cliente y fecha anual, aunque haya varios pedidos ese día.
        key = f"cupon:{order['cliente_id']}:{target}"
        code = 'FLORA-' + secrets.token_hex(6).upper()
        expires = target + timedelta(days=7)
        percent = max(1, min(50, int(os.getenv('COUPON_PERCENT', '10'))))
        cur.execute('INSERT INTO fe_cupon (codigo,cliente_id,porcentaje,vence,clave) VALUES (%s,%s,%s,%s,%s) ON CONFLICT DO NOTHING',
                    (code, order['cliente_id'], percent, expires, key))
        cur.execute('SELECT * FROM fe_cupon WHERE clave=%s', (key,))
        coupon = cur.fetchone()
        enqueue(cur, key, 'cupon_email', {'email': order['email'], 'codigo': coupon['codigo'],
                'porcentaje': coupon['porcentaje'], 'vence': coupon['vence']})

    start, end = previous_month(today)
    # El día 1 se informa el mes completamente cerrado; recupera una caída posterior.
    key = 'mensual:' + start.strftime('%Y-%m')
    cur.execute('SELECT id FROM fe_job WHERE clave=%s', (key,))
    if not cur.fetchone():
        cur.execute("SELECT id,total FROM pedido WHERE fecha >= %s AND fecha < %s AND estado <> 'Cancelado'", (start, end))
        orders = cur.fetchall()
        cur.execute("""SELECT pd.cantidad,pd.costo_unitario FROM pedido_detalle pd JOIN pedido p ON p.id=pd.pedido_id
                       WHERE p.fecha >= %s AND p.fecha < %s AND p.estado <> 'Cancelado'""", (start, end))
        summary = financial_summary(orders, cur.fetchall())
        profit = summary['utilidad_bruta'] if summary['utilidad_bruta'] is not None else 'No calculable: faltan costos históricos'
        enqueue(cur, key, 'telegram', {'texto': f"Cierre {start:%Y-%m}\nPedidos no cancelados: {summary['pedidos']}\nVentas registradas: COP {summary['ventas']}\nUtilidad bruta: {profit}\nNo incluye gastos operativos; pedidos no equivalen a pagos verificados."})
    audit(cur, 'programador', str(today), 'stock, aniversarios y cierre revisados')


def log_flow(cur, name, started, entry, status, result):
    # La observabilidad no debe impedir que un mensaje se entregue: si la
    # migración aún no existe, se omite el log y el envío continúa.
    try:
        cur.execute('INSERT INTO logs_automatizacion (nombre_flujo,fecha_inicio,fecha_fin,datos_entrada,estado,resultado_o_error)'
                    ' VALUES (%s,%s,%s,%s,%s,%s) RETURNING id',
                    (name[:80], started, now(), json.dumps(entry, ensure_ascii=False, default=str),
                     status, str(result)[:300]))
        row = cur.fetchone()
        return row['id'] if row else None
    except Exception:
        return None


def tick():
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT pg_try_advisory_lock(hashtext('flora_worker')) AS acquired")
            if not cur.fetchone()['acquired']:
                return
            try:
                scheduled(cur, now().date())
                log_flow(cur, 'programador', now(), {'fecha': str(now().date())}, 'EXITO',
                         'stock, aniversarios y cierre revisados')
                conn.commit()
            except Exception as exc:
                conn.rollback()
                log_flow(cur, 'programador', now(), {'fecha': str(now().date())}, 'ERROR',
                         type(exc).__name__ + ': revisar migración y base de datos')
                conn.commit()
            cur.execute("SELECT * FROM fe_job WHERE estado='pendiente' AND disponible <= NOW() ORDER BY id LIMIT 30")
            jobs = cur.fetchall()
            for job in jobs:
                attempts = job['intentos'] + 1
                started = now()
                log_id = log_flow(cur, 'envio_' + job['canal'], started,
                                  {'clave': job['clave'], 'canal': job['canal']}, 'PENDIENTE', '')
                try:
                    deliver(job)
                    status, result = 'enviado', 'Proveedor aceptó el mensaje'
                except Exception as exc:
                    status = 'fallido' if attempts >= 5 else 'pendiente'
                    # No registrar str(exc): puede incluir URLs con el token del bot.
                    result = type(exc).__name__ + ': revisar configuración/conectividad del proveedor'
                delay = min(3600, 60 * 2 ** (attempts - 1))
                cur.execute('UPDATE fe_job SET estado=%s,intentos=%s,resultado=%s,disponible=%s WHERE id=%s',
                            (status, attempts, result, now() + timedelta(seconds=delay), job['id']))
                if log_id:
                    cur.execute('UPDATE logs_automatizacion SET fecha_fin=%s,estado=%s,resultado_o_error=%s WHERE id=%s',
                                (now(), 'EXITO' if status == 'enviado' else 'ERROR',
                                 f'intento {attempts}; {result}', log_id))
                audit(cur, job['canal'], job['clave'], f'{status}; intento {attempts}; {result}')
                conn.commit()
    finally:
        # Cerrar la conexión libera también el lock advisory, incluso tras un error.
        conn.close()


if __name__ == '__main__':
    while True:
        try:
            tick()
        except Exception as exc:
            print('worker:', type(exc).__name__, '- revisar base de datos/migración', flush=True)
        time.sleep(60)
