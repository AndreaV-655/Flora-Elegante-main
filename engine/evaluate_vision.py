"""Mide fotos reales sin inventar resultados. Ejecutar en el mismo entorno del motor.

CSV UTF-8: archivo,producto_esperado (vacío si no existe coincidencia).
Procesa localmente, no crea pedidos ni notifica a nadie. Salida con resultados
por foto y exactitud global. Exige al menos 20 fotos distintas.
"""
import csv
import json
import base64
import sys
from pathlib import Path
from app import normalize_image, vision_flow
from storage import connect


def evaluate(manifest, output):
    source = Path(manifest).resolve()
    with source.open(encoding='utf-8-sig', newline='') as file:
        rows = list(csv.DictReader(file))
    paths = [(source.parent / row['archivo']).resolve() for row in rows]
    if len(rows) < 20 or len(set(paths)) < 20 or not all(p.is_file() for p in paths):
        raise ValueError('Se necesitan al menos 20 fotografías reales distintas y existentes')
    with connect() as conn, conn.cursor() as cur:
        cur.execute("SELECT id,nombre,descripcion FROM producto WHERE estado='activo'")
        catalog = cur.fetchall()
    results = []
    for row, path in zip(rows, paths):
        expected = int(row['producto_esperado']) if row['producto_esperado'].strip() else None
        try:
            image = normalize_image(base64.b64encode(path.read_bytes()).decode())
            result = vision_flow.invoke({'image': base64.b64encode(image).decode(), 'catalog': catalog})['result']
            results.append({'archivo': path.name, 'esperado': expected, **result, 'acierto': result['producto_id'] == expected})
        except Exception as exc:
            results.append({'archivo': path.name, 'esperado': expected, 'acierto': False, 'error': type(exc).__name__})
    report = {'total': len(results), 'aciertos': sum(r['acierto'] for r in results),
              'porcentaje_aciertos': 100 * sum(r['acierto'] for r in results) / len(results),
              'errores_procesamiento': sum('error' in r for r in results), 'resultados': results}
    Path(output).write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    print(f"{report['aciertos']}/{report['total']} = {report['porcentaje_aciertos']:.1f}%")


if __name__ == '__main__':
    evaluate(sys.argv[1], sys.argv[2])
