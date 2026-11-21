import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def get_env(name):
    value = os.getenv(name, '').strip()
    if value:
        return value
    path = ROOT / '.env'
    if not path.is_file():
        return ''
    for line in path.read_text(encoding='utf-8-sig').splitlines():
        line = line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, val = line.split('=', 1)
        if key.strip() == name:
            return val.strip()
    return ''


def api(token, method, payload=None):
    url = f'https://api.telegram.org/bot{token}/{method}'
    data = json.dumps(payload).encode('utf-8') if payload is not None else None
    request = urllib.request.Request(url, data=data, headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return json.load(response)
    except urllib.error.HTTPError as exc:
        exc.close()
        return {'ok': False, 'error_code': exc.code, 'description': 'HTTP ' + str(exc.code)}
    except (urllib.error.URLError, TimeoutError, ValueError):
        return {'ok': False, 'description': 'Sin respuesta válida de Telegram'}


def main():
    token = get_env('TELEGRAM_BOT_TOKEN')
    chat = get_env('TELEGRAM_CHAT_ID')
    if not token:
        print('Falta TELEGRAM_BOT_TOKEN. Obtenlo con @BotFather (/token si ya existe el bot).')
        return 1
    if not re.fullmatch(r'\d+:[A-Za-z0-9_-]{30,}', token):
        print('El token no tiene el formato de un Bot API Token; revísalo sin compartirlo.')
        return 1
    me = api(token, 'getMe')
    if not me.get('ok'):
        print('Token rechazado por Telegram:', me.get('description', 'error desconocido'))
        return 1
    username = me.get('result', {}).get('username', '')
    print('Token válido. Bot:', '@' + username if username else '(sin username)')
    if username.lower() != 'floraelegantebot':
        print('Advertencia: el token no corresponde a @FloraEleganteBot.')
    if not chat:
        print('Falta TELEGRAM_CHAT_ID. Envía /start al bot y consulta getUpdates.')
        return 1
    if not re.fullmatch(r'-?\d{5,}', chat):
        print('TELEGRAM_CHAT_ID debe ser numérico; los grupos empiezan con -.')
        return 1
    sent = api(token, 'sendMessage', {
        'chat_id': chat,
        'text': 'Flora Elegante: prueba de automatización. Si ves este mensaje, Telegram está conectado.',
    })
    if not sent.get('ok'):
        print('No se pudo enviar el mensaje:', sent.get('description', 'error desconocido'))
        return 1
    print('Mensaje de prueba enviado al chat terminado en', chat[-4:])
    return 0


if __name__ == '__main__':
    sys.exit(main())
