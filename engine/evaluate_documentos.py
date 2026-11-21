"""Mide la extracción de facturas reales sin inventar resultados.

CSV UTF-8: archivo,proveedor,numero_factura,fecha,total,moneda
Las columnas esperadas pueden quedar vacías para no evaluar ese campo.
Exige al menos 20 documentos anonimizados. Envía imágenes a Cloudflare y texto
a Pollinations; no guarda en BD. Requiere --allow-cloud-anonymized en CLI.
"""
import base64
import argparse
import csv
import json
import sys
from datetime import datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path

from app import document_flow, normalize_document
from core import documento_datos

FIELDS = ('proveedor', 'numero_factura', 'fecha', 'total', 'moneda')


def clean(field, value):
    text = '' if value is None else str(value).strip()
    if field == 'proveedor':
        return ' '.join(text.casefold().split())
    if field in ('numero_factura', 'moneda'):
        return text.upper().replace(' ', '').replace('-', '')
    if field == 'total':
        try:
            return documento_datos({'total': text})['total']
        except (InvalidOperation, ValueError):
            return text
    return text


def evaluate(manifest, output):
    source = Path(manifest).resolve()
    with source.open(encoding='utf-8-sig', newline='') as file:
        rows = list(csv.DictReader(file))
    paths = [(source.parent / row['archivo']).resolve() for row in rows]
    if len(rows) < 20 or len(set(paths)) < 20 or not all(p.is_file() for p in paths):
        raise ValueError('Se necesitan al menos 20 documentos reales distintos y existentes')
    totals = {field: {'aciertos': 0, 'evaluados': 0} for field in FIELDS}
    results = []
    for row, path in zip(rows, paths):
        # Los fallos de proveedor cuentan como errores de cada campo esperado;
        # excluirlos del denominador inflaría la precisión del OCR.
        expected_fields = {field: clean(field, row.get(field)) for field in FIELDS if row.get(field, '').strip()}
        for field in expected_fields:
            totals[field]['evaluados'] += 1
        try:
            image, kind = normalize_document(base64.b64encode(path.read_bytes()).decode())
            result = document_flow.invoke({'image': base64.b64encode(image).decode()})['result']
            checks = {}
            for field, expected in expected_fields.items():
                checks[field] = clean(field, result.get(field)) == expected
                totals[field]['aciertos'] += int(checks[field])
            results.append({'archivo': path.name, 'tipo': kind, 'confianza': result['confianza'],
                            'campos': checks, 'completo': all(checks.values()) if checks else False,
                            'obtenido': {field: result.get(field) for field in FIELDS}})
        except Exception as exc:
            results.append({'archivo': path.name, 'error': type(exc).__name__, 'completo': False})
    for field in FIELDS:
        data = totals[field]
        data['porcentaje'] = 100 * data['aciertos'] / data['evaluados'] if data['evaluados'] else None
    report = {'fecha': datetime.now().isoformat(timespec='seconds'), 'total': len(results),
              'documentos_completos': sum(r['completo'] for r in results),
              'porcentaje_documentos_completos': 100 * sum(r['completo'] for r in results) / len(results),
              'errores_procesamiento': sum('error' in r for r in results),
              'por_campo': totals, 'resultados': results}
    Path(output).write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    for field in FIELDS:
        data = totals[field]
        if data['porcentaje'] is not None:
            print(f"{field}: {data['aciertos']}/{data['evaluados']} = {data['porcentaje']:.1f}%")
    print(f"documentos completos: {report['documentos_completos']}/{report['total']} = "
          f"{report['porcentaje_documentos_completos']:.1f}%")


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('manifest')
    parser.add_argument('output')
    parser.add_argument('--allow-cloud-anonymized', action='store_true', required=True,
                        help='Confirmo que anonimicé los documentos y autorizo Cloudflare/Pollinations')
    args = parser.parse_args()
    evaluate(args.manifest, args.output)
