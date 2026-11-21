"""Contratos de proveedores y fallos del flujo sin enviar fotos ni usar claves reales."""
import base64
import io
import json
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import MagicMock, patch
from urllib.error import HTTPError

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'engine'))
import document_ocr as ocr
from core import documento_datos


def reply(data):
    response = MagicMock()
    response.__enter__.return_value.read.return_value = json.dumps(data).encode()
    return response


class OCRContracts(unittest.TestCase):
    def test_cloudflare_receives_image_bytes_and_returns_text(self):
        with patch.dict(os.environ, {'CLOUDFLARE_ACCOUNT_ID': 'a' * 32, 'CLOUDFLARE_API_TOKEN': 'test'}):
            with patch.object(ocr, 'post_json', return_value={'success': True, 'result': {'response': 'Factura 10'}}) as post:
                self.assertEqual(ocr.cloudflare_text(base64.b64encode(b'photo').decode()), 'Factura 10')
        self.assertEqual(post.call_args.args[2]['image'], list(b'photo'))

    def test_missing_cloudflare_keys_no_network(self):
        with patch.dict(os.environ, {'CLOUDFLARE_API_TOKEN': '', 'CLOUDFLARE_ACCOUNT_ID': ''}):
            with patch.object(ocr, 'post_json') as post, self.assertRaises(ocr.OCRError):
                ocr.cloudflare_text('')
            post.assert_not_called()

    def test_empty_ocr_is_not_sent_to_interpreter(self):
        with patch.dict(os.environ, {'CLOUDFLARE_ACCOUNT_ID': 'a' * 32, 'CLOUDFLARE_API_TOKEN': 'test'}):
            with patch.object(ocr, 'post_json', return_value={'success': True, 'result': {'response': '[sin texto]'}}):
                with self.assertRaises(ocr.OCRError):
                    ocr.cloudflare_text('')

    def test_pollinations_receives_only_text(self):
        response = {'choices': [{'message': {'content': '{"total":10,"confianza":0.8}'}, 'finish_reason': 'stop'}]}
        with patch.dict(os.environ, {'POLLINATIONS_API_KEY': 'test'}):
            with patch.object(ocr, 'post_json', return_value=response) as post:
                self.assertEqual(ocr.pollinations_fields('Total 10')['total'], 10)
        payload = post.call_args.args[2]
        self.assertEqual(payload['messages'][1]['content'], 'Total 10')
        self.assertNotIn('image', json.dumps(payload))

    def test_truncated_json_rejected(self):
        response = {'choices': [{'message': {'content': '{}'}, 'finish_reason': 'length'}]}
        with patch.dict(os.environ, {'POLLINATIONS_API_KEY': 'test'}):
            with patch.object(ocr, 'post_json', return_value=response), self.assertRaises(ocr.OCRError):
                ocr.pollinations_fields('Total 10')

    def test_retry_429_then_success(self):
        failure = HTTPError('https://provider.test', 429, 'limit', {}, io.BytesIO(b'secret'))
        with patch.object(ocr.urllib.request, 'urlopen', side_effect=[failure, reply({'ok': True})]) as call:
            with patch.object(ocr.time, 'sleep'):
                self.assertTrue(ocr.post_json('https://provider.test', 'test', {}, 'Proveedor')['ok'])
        self.assertEqual(call.call_count, 2)

    def test_auth_failure_not_retried_or_leaked(self):
        failure = HTTPError('https://provider.test?secret=hidden', 401, 'hidden', {}, io.BytesIO(b'hidden'))
        with patch.object(ocr.urllib.request, 'urlopen', side_effect=failure) as call:
            with self.assertRaises(ocr.OCRError) as error:
                ocr.post_json('https://provider.test', 'test', {}, 'Proveedor')
        self.assertEqual(call.call_count, 1)
        self.assertNotIn('hidden', str(error.exception))

    def test_decimal_comma(self):
        self.assertEqual(documento_datos({'total': '1250,50'})['total'], '1250.50')

    def test_colombian_thousands(self):
        self.assertEqual(documento_datos({'total': '1.250,50'})['total'], '1250.50')

    def test_ambiguous_amount_rejected(self):
        with self.assertRaises(ValueError):
            documento_datos({'total': '1,250'})


class OCRWorkflow(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        os.environ['VISION_STORAGE'] = str(Path(__file__).parent / 'results' / 'vision')
        import app
        cls.app = app

    def test_consent_required_before_any_upload(self):
        from fastapi import HTTPException
        with patch.object(self.app, 'connect') as conn, self.assertRaises(HTTPException) as error:
            self.app.documento_analyze(self.app.DocumentInput(usuario_id=1))
        self.assertEqual(error.exception.status_code, 400)
        conn.assert_not_called()

    def test_pollinations_failure_preserves_ocr_and_allows_manual(self):
        conn = MagicMock()
        cursor = conn.__enter__.return_value.cursor.return_value.__enter__.return_value
        events = []
        cursor.execute.side_effect = lambda *args: events.append(args)
        with patch.object(self.app, 'connect', return_value=conn), patch.object(self.app, 'document_role', return_value='empleado'):
            with patch.object(self.app, 'normalize_document', return_value=(b'normalized', 'imagen')):
                with patch.object(self.app, 'cloudflare_text', return_value='Total 10'):
                    with patch.object(self.app, 'pollinations_fields', side_effect=ocr.OCRError('Pollinations no disponible')):
                        with patch.object(Path, 'write_bytes'):
                            result = self.app.documento_analyze(self.app.DocumentInput(usuario_id=1, consentimiento=True, anonimizado=True))
        self.assertTrue(result['manual'])
        self.assertEqual(result['texto_extraido'], 'Total 10')
        self.assertTrue(any('Total 10' in str(args) and 'UPDATE fe_documento SET resultado' in args[0] for args in events))

    def test_multipage_pdf_rejected(self):
        import pymupdf
        with pymupdf.open() as pdf:
            pdf.new_page(); pdf.new_page()
            encoded = base64.b64encode(pdf.tobytes()).decode()
        with self.assertRaises(ValueError):
            self.app.normalize_document(encoded)

    def test_failed_document_can_be_completed_by_owner(self):
        conn = MagicMock()
        cur = conn.__enter__.return_value.cursor.return_value.__enter__.return_value
        cur.fetchone.return_value = {'estado': 'fallido', 'empleado_id': 1}
        data = self.app.DocumentInput(usuario_id=1, id='a' * 32, datos={
            'proveedor': 'Empresa', 'numero_factura': 'A1', 'fecha': '2026-11-20', 'total': '10.00', 'moneda': 'COP'})
        with patch.object(self.app, 'connect', return_value=conn), patch.object(self.app, 'document_role', return_value='empleado'):
            self.assertTrue(self.app.documento_confirm(data)['ok'])

    def test_other_employee_cannot_confirm(self):
        from fastapi import HTTPException
        conn = MagicMock()
        cur = conn.__enter__.return_value.cursor.return_value.__enter__.return_value
        cur.fetchone.return_value = {'estado': 'revisar', 'empleado_id': 2}
        data = self.app.DocumentInput(usuario_id=1, id='a' * 32, datos={
            'proveedor': 'Empresa', 'numero_factura': 'A1', 'fecha': '2026-11-20', 'total': '10.00', 'moneda': 'COP'})
        with patch.object(self.app, 'connect', return_value=conn), patch.object(self.app, 'document_role', return_value='empleado'):
            with self.assertRaises(HTTPException) as error:
                self.app.documento_confirm(data)
        self.assertEqual(error.exception.status_code, 404)
