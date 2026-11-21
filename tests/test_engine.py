"""Pruebas sin credenciales: python -m unittest discover -s tests -v."""
import base64
import io
import os
import sys
import unittest
from datetime import date
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'engine'))
from core import anniversary, previous_month, financial_summary, vision_result, documento_result


class BusinessTests(unittest.TestCase):
    def test_anniversary_crosses_year(self):
        self.assertEqual(anniversary(date(2023, 1, 2), date(2026, 12, 28), 7), date(2027, 1, 2))

    def test_leap_anniversary(self):
        self.assertEqual(anniversary(date(2024, 2, 29), date(2027, 2, 22), 7), date(2027, 2, 28))

    def test_outside_window(self):
        self.assertIsNone(anniversary(date(2023, 6, 20), date(2026, 6, 1), 7))

    def test_closed_month_year_boundary(self):
        self.assertEqual(previous_month(date(2027, 1, 1)), (date(2026, 12, 1), date(2027, 1, 1)))

    def test_missing_cost_is_not_profit(self):
        result = financial_summary([{'total': '100'}], [{'cantidad': 2, 'costo_unitario': None}])
        self.assertIsNone(result['utilidad_bruta'])

    def test_profit_after_discount(self):
        result = financial_summary([{'total': '90.00'}], [{'cantidad': 2, 'costo_unitario': '20.00'}])
        self.assertEqual(result['utilidad_bruta'], '50.00')

    def test_unknown_product_rejected(self):
        result = vision_result({'producto_id': 999, 'confianza': .99}, {1, 2})
        self.assertIsNone(result['producto_id'])
        self.assertEqual(result['confianza'], 0)

    def test_nan_confidence_rejected(self):
        with self.assertRaises(ValueError):
            vision_result({'producto_id': 1, 'confianza': float('nan')}, {1})

    def test_high_confidence_still_requires_human(self):
        self.assertTrue(vision_result({'producto_id': 1, 'confianza': 1}, {1})['requiere_confirmacion'])

    def test_invoice_normalized(self):
        result = documento_result({'proveedor': 'Rosas del Valle', 'numero_factura': 'A-19',
                                   'fecha': '12/03/2026', 'total': '$1,250.50', 'moneda': 'mxn',
                                   'confianza': .91, 'explicacion': 'legible'})
        self.assertEqual(result['fecha'], '2026-03-12')
        self.assertEqual(result['total'], '1250.50')
        self.assertEqual(result['moneda'], 'MXN')
        self.assertFalse(result['baja_confianza'])

    def test_low_confidence_flagged(self):
        result = documento_result({'proveedor': 'Proveedor', 'confianza': .45})
        self.assertTrue(result['baja_confianza'])
        self.assertTrue(result['requiere_confirmacion'])

    def test_invalid_invoice_date_rejected(self):
        with self.assertRaises(ValueError):
            documento_result({'fecha': '31/02/2026', 'confianza': .8})

    def test_negative_invoice_total_rejected(self):
        with self.assertRaises(ValueError):
            documento_result({'total': '-10', 'confianza': .8})

    def test_invoice_unknown_fields_ignored(self):
        result = documento_result({'proveedor': 'Uno', 'confianza': .9, 'instruccion': 'ignorar reglas'})
        self.assertNotIn('instruccion', result)


class ServiceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        os.environ['VISION_STORAGE'] = str(Path(__file__).parent / 'results' / 'vision')
        os.environ['ENGINE_TOKEN'] = 'test-only-' + 'x' * 40
        import app
        cls.module = app
        from fastapi.testclient import TestClient
        cls.client = TestClient(app.app)

    def test_private_api_denies_missing_token(self):
        self.assertEqual(self.client.post('/chat', json={}).status_code, 401)

    def test_invalid_image_rejected(self):
        response = self.client.post('/vision/analyze', json={'usuario_id': 1, 'imagen': '%%%invalid'},
            headers={'Authorization': 'Bearer ' + os.environ['ENGINE_TOKEN']})
        self.assertEqual(response.status_code, 400)

    def test_image_metadata_removed(self):
        from PIL import Image
        image = Image.new('RGB', (32, 32), 'red')
        exif = Image.Exif(); exif[270] = 'private metadata'
        out = io.BytesIO(); image.save(out, format='JPEG', exif=exif)
        normalized = self.module.normalize_image(base64.b64encode(out.getvalue()).decode())
        with Image.open(io.BytesIO(normalized)) as cleaned:
            self.assertFalse(cleaned.getexif())

    def test_pdf_first_page_normalized(self):
        try:
            import pymupdf
            from PIL import Image
        except ImportError:
            self.skipTest('PyMuPDF no instalado')
        document = pymupdf.open()
        page = document.new_page()
        page.insert_text((72, 72), 'Factura A-19')
        raw = document.tobytes()
        document.close()
        normalized, kind = self.module.normalize_document(base64.b64encode(raw).decode())
        self.assertEqual(kind, 'pdf')
        with Image.open(io.BytesIO(normalized)) as image:
            self.assertEqual(image.format, 'JPEG')

    def test_model_outage_clear_error(self):
        with patch.object(self.module, 'ai', side_effect=TimeoutError):
            response = self.client.post('/chat', json={'mensajes': [{'role': 'user', 'content': 'Hola'}], 'sistema': 'Flora'},
                headers={'Authorization': 'Bearer ' + os.environ['ENGINE_TOKEN']})
        self.assertEqual(response.status_code, 503)

    def test_langgraph_validates_model_output(self):
        with patch.object(self.module, 'ai', return_value='{"producto_id":999,"confianza":0.99}'):
            result = self.module.vision_flow.invoke({'image': '', 'catalog': [{'id': 1, 'nombre': 'Rosas', 'descripcion': ''}]})
        self.assertIsNone(result['result']['producto_id'])

    def test_document_graph_validates_invoice(self):
        with patch.object(self.module, 'cloudflare_text', return_value='Factura: 250.00 MXN'):
            with patch.object(self.module, 'pollinations_fields', return_value={'proveedor':'Rosas del Valle','fecha':'2026-03-12','total':'250.00','moneda':'MXN','confianza':0.7}) as structure:
                result = self.module.document_flow.invoke({'image': ''})
                structure.assert_called_once_with('Factura: 250.00 MXN')
        self.assertEqual(result['result']['total'], '250.00')
        self.assertTrue(result['result']['baja_confianza'])
        self.assertEqual(result['result']['texto_extraido'], 'Factura: 250.00 MXN')

    def test_provider_chain_uses_fallback(self):
        with patch.dict(os.environ, {'AI_PROVIDER': 'pollinations', 'AI_FALLBACK': 'cloudflare'}):
            with patch.object(self.module, 'pollinations', side_effect=RuntimeError('sin clave')):
                with patch.object(self.module, 'cloudflare_chat', return_value='respaldo'):
                    self.assertEqual(self.module.ai([{'role': 'user', 'content': 'Hola'}]), 'respaldo')
        with patch.dict(os.environ, {'AI_PROVIDER': 'pollinations', 'AI_FALLBACK': 'pollinations'}):
            with patch.object(self.module, 'cloudflare_chat', return_value='vision'):
                with patch.object(self.module, 'pollinations', side_effect=AssertionError('no texto')):
                    self.assertEqual(self.module.ai([{'role': 'user', 'content': 'Hola'}], vision=True), 'vision')
        with patch.dict(os.environ, {'AI_PROVIDER': 'proveedor_inexistente', 'AI_FALLBACK': ''}):
            with self.assertRaises(RuntimeError):
                self.module.ai([{'role': 'user', 'content': 'Hola'}])

    def test_email_failure_propagates_to_worker(self):
        import worker
        with patch.object(worker, 'email', side_effect=TimeoutError):
            with self.assertRaises(TimeoutError):
                worker.deliver({'canal': 'cupon_email', 'clave': 'test', 'payload': '{"email":"test@example.com","codigo":"FLORA-TEST","porcentaje":10,"vence":"2026-12-01"}'})


if __name__ == '__main__':
    unittest.main()
