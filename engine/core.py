"""Reglas puras del negocio: fáciles de probar sin correo, IA ni base de datos."""
from datetime import date, datetime, timedelta
from decimal import Decimal, InvalidOperation
import math
import re


def anniversary(source, today, days):
    """Próxima repetición anual; el 29 de febrero pasa al 28 en años comunes."""
    for year in (today.year, today.year + 1):
        try:
            target = date(year, source.month, source.day)
        except ValueError:
            target = date(year, 2, 28)
        if today <= target <= today + timedelta(days=days):
            return target
    return None


def previous_month(today):
    end = today.replace(day=1)
    return (end - timedelta(days=1)).replace(day=1), end


def vision_result(raw, allowed):
    """El modelo propone; el código limita IDs y confianza. No modifica stock."""
    pid = raw.get('producto_id')
    if type(pid) is not int or pid not in allowed:
        pid = None
    confidence = float(raw.get('confianza', 0))
    if not math.isfinite(confidence) or not 0 <= confidence <= 1:
        raise ValueError('Confianza inválida')
    return {'producto_id': pid, 'confianza': confidence if pid else 0,
            'explicacion': str(raw.get('explicacion', ''))[:400],
            'requiere_confirmacion': True}


def _documento_texto(value, limit):
    if value is None:
        return None
    if not isinstance(value, (str, int, float)):
        raise ValueError('Campo de texto inválido')
    text = str(value).strip()
    return text[:limit] or None


def _documento_fecha(value):
    if value in (None, ''):
        return None
    text = str(value).strip()
    for pattern in ('%Y-%m-%d', '%d/%m/%Y', '%d-%m-%Y'):
        try:
            return datetime.strptime(text, pattern).date().isoformat()
        except ValueError:
            continue
    raise ValueError('Fecha inválida')


def _documento_total(value):
    if value in (None, ''):
        return None
    # OCR puede devolver formato 1.250,50 o 1,250.50. Nunca borrar comas
    # indiscriminadamente: 1250,50 terminaría convertido en 125050.
    text = str(value).strip().replace('$', '').replace(' ', '').replace('\u00a0', '')
    if isinstance(value, bool):
        raise ValueError('Total inválido')
    if isinstance(value, str):
        if ',' in text and '.' in text:
            decimal = ',' if text.rfind(',') > text.rfind('.') else '.'
            thousands = '.' if decimal == ',' else ','
            integer, fraction = text.rsplit(decimal, 1)
            if not re.fullmatch(r'\d{1,3}(?:' + re.escape(thousands) + r'\d{3})+', integer) or not re.fullmatch(r'\d{1,2}', fraction):
                raise ValueError('Separadores de total inválidos')
            text = integer.replace(thousands, '') + '.' + fraction
        elif ',' in text or '.' in text:
            if not re.fullmatch(r'\d+[.,]\d{1,2}', text):
                raise ValueError('Importe ambiguo: escribe dos decimales y sin miles')
            text = text.replace(',', '.')
        elif not re.fullmatch(r'\d+', text):
            raise ValueError('Total inválido')
    try:
        total = Decimal(text)
    except InvalidOperation as exc:
        raise ValueError('Total inválido') from exc
    if not total.is_finite() or total < 0 or total >= Decimal('1000000000000'):
        raise ValueError('Total inválido')
    return str(total.quantize(Decimal('0.01')))


def _documento_moneda(value):
    if value in (None, ''):
        return None
    text = str(value).strip().upper()
    return text if re.fullmatch(r'[A-Z]{3}', text) else None


def documento_datos(raw):
    """Normaliza la factura que propone la IA. El empleado siempre confirma."""
    if not isinstance(raw, dict):
        raise ValueError('Datos inválidos')
    return {
        'proveedor': _documento_texto(raw.get('proveedor'), 120),
        'numero_factura': _documento_texto(raw.get('numero_factura'), 60),
        'fecha': _documento_fecha(raw.get('fecha')),
        'total': _documento_total(raw.get('total')),
        'moneda': _documento_moneda(raw.get('moneda')),
    }


def documento_result(raw):
    """Marca baja confianza para que la interfaz exija revisión humana."""
    datos = documento_datos(raw)
    confidence = float(raw.get('confianza', 0))
    if not math.isfinite(confidence) or not 0 <= confidence <= 1:
        raise ValueError('Confianza inválida')
    return {**datos, 'confianza': confidence, 'baja_confianza': confidence < 0.8,
            'explicacion': str(raw.get('explicacion', ''))[:400],
            'requiere_confirmacion': True}


def financial_summary(orders, details):
    """Ventas netas de cupones y utilidad bruta; nunca inventa costos faltantes."""
    sales = sum((Decimal(str(o['total'])) for o in orders), Decimal(0))
    missing = sum(d['costo_unitario'] is None for d in details)
    costs = sum((Decimal(str(d['costo_unitario'] or 0)) * d['cantidad']
                 for d in details), Decimal(0))
    return {'pedidos': len(orders), 'ventas': str(sales),
            'costos_faltantes': missing,
            'utilidad_bruta': None if missing else str(sales - costs)}
