// Módulo de empleado: la IA propone y la persona confirma.
// Nunca se inyecta texto devuelto por el modelo como HTML.
const Empleado = (() => {
    const $ = (id) => document.getElementById(id);
    let currentId = '';
    let previewUrl = '';
    let busy = false;

    function setConfidence(result) {
        const box = $('docConfidence');
        box.classList.remove('hidden');
        box.className = 'mb-4 p-3 rounded-xl text-sm ' + (result.baja_confianza
            ? 'bg-red-50 text-red-700 border border-red-200'
            : 'bg-green-50 text-green-700 border border-green-200');
        const percent = Math.round(Number(result.confianza || 0) * 100);
        box.textContent = result.baja_confianza
            ? `Confianza estimada ${percent}%: revisa cada campo antes de confirmar.`
            : `Confianza estimada ${percent}%. Verifica que coincida con el documento.`;
        if (result.manual) box.textContent = 'No se completó el análisis. Puedes ingresar los datos manualmente y confirmarlos.';
    }

    function fillForm(datos) {
        $('docProveedor').value = datos.proveedor || '';
        $('docNumero').value = datos.numero_factura || '';
        $('docFecha').value = datos.fecha || '';
        $('docTotal').value = datos.total || '';
        $('docMoneda').value = datos.moneda || '';
        $('docExplanation').textContent = datos.explicacion || '';
        $('docOcrText').textContent = datos.texto_extraido || 'No hay texto extraído disponible.';
    }

    function resetForm() {
        currentId = '';
        $('docForm').reset();
        $('docConfidence').classList.add('hidden');
        $('docExplanation').textContent = '';
        $('docOcrText').textContent = 'Todavía no hay texto extraído.';
        $('docConfirm').disabled = true;
        $('docFile').value = '';
        $('docPreview').classList.add('hidden');
        $('docPreviewImg').classList.add('hidden');
        $('docPreviewImg').removeAttribute('src');
        $('docPreviewName').textContent = '';
        $('docConsent').checked = false;
        $('docAnonimizado').checked = false;
        if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = ''; }
    }

    function preview(file) {
        if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = ''; }
        $('docPreview').classList.remove('hidden');
        $('docPreviewName').textContent = `${file.name} · ${(file.size / 1024).toFixed(0)} KB`;
        const image = $('docPreviewImg');
        if (file.type.startsWith('image/')) {
            previewUrl = URL.createObjectURL(file);
            image.src = previewUrl;
            image.classList.remove('hidden');
        } else {
            image.classList.add('hidden');
            $('docPreviewName').textContent += ' · PDF (debe contener una sola página)';
        }
    }

    function readBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
            reader.onerror = () => reject(new Error('No se pudo leer el archivo'));
            reader.readAsDataURL(file);
        });
    }

    async function analyze() {
        if (busy) return;
        const file = $('docFile').files[0];
        if (!file) { showToast('Selecciona una foto o un PDF.', 'info'); return; }
        if (!['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type)) {
            showToast('Formato no permitido. Usa JPG, PNG, WebP o PDF.', 'error'); return;
        }
        if (file.size > 5 * 1024 * 1024) { showToast('El archivo supera 5 MB.', 'error'); return; }
        if (!$('docConsent').checked) { showToast('Autoriza el tratamiento del documento.', 'error'); return; }
        if (!$('docAnonimizado').checked) { showToast('Confirma que ocultaste los datos personales.', 'error'); return; }
        busy = true;
        currentId = '';
        fillForm({});
        $('docConfidence').classList.add('hidden');
        $('docConfirm').disabled = true;
        $('docFile').disabled = true;
        $('docCancel').disabled = true;
        const button = $('docAnalyze');
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Analizando…';
        try {
            const imagen = await readBase64(file);
            const result = await API.request('empleado', 'analyze', { imagen, consentimiento: true, anonimizado: true }, 'POST');
            currentId = result.id;
            fillForm(result);
            setConfidence(result);
            $('docConfirm').disabled = false;
            showToast(result.manual ? 'Análisis incompleto. Completa los datos manualmente.' : 'Texto y datos listos para revisión.', result.manual ? 'info' : 'success');
            $('docForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            busy = false;
            $('docFile').disabled = false;
            $('docCancel').disabled = false;
            button.disabled = false;
            button.innerHTML = original;
        }
    }

    async function confirm(event) {
        event.preventDefault();
        if (busy) return;
        if (!currentId) { showToast('Primero analiza un documento.', 'info'); return; }
        const datos = {
            proveedor: $('docProveedor').value.trim(),
            numero_factura: $('docNumero').value.trim(),
            fecha: $('docFecha').value,
            total: $('docTotal').value,
            moneda: $('docMoneda').value.trim().toUpperCase(),
        };
        if (!datos.proveedor || !datos.numero_factura || !datos.fecha || !datos.total || !datos.moneda) {
            showToast('Completa proveedor, número, fecha, total y moneda.', 'error'); return;
        }
        const button = $('docConfirm');
        const original = button.innerHTML;
        busy = true;
        $('docFile').disabled = true;
        $('docAnalyze').disabled = true;
        $('docCancel').disabled = true;
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Guardando…';
        try {
            await API.request('empleado', 'confirm', { id: currentId, datos }, 'POST');
            showToast('Documento confirmado y guardado.', 'success');
            resetForm();
            await loadHistory();
        } catch (error) {
            showToast(error.message, 'error');
            button.disabled = false;
            button.innerHTML = original;
        } finally {
            busy = false;
            $('docFile').disabled = false;
            $('docAnalyze').disabled = false;
            $('docCancel').disabled = false;
            button.innerHTML = original;
        }
    }

    function badge(text, tone) {
        const span = document.createElement('span');
        span.className = 'text-[11px] font-bold px-2 py-0.5 rounded-full ' + tone;
        span.textContent = text;
        return span;
    }

    function renderHistory(items) {
        const box = $('docHistory');
        box.replaceChildren();
        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'text-muted text-sm';
            empty.textContent = 'Todavía no hay documentos analizados.';
            box.append(empty);
            return;
        }
        for (const doc of items) {
            const card = document.createElement('article');
            card.className = 'bg-white rounded-xl border border-bord p-4 flex flex-wrap items-center gap-3';
            const data = doc.datos_confirmados || doc.resultado || {};
            const info = document.createElement('div');
            info.className = 'flex-1 min-w-[220px]';
            const title = document.createElement('p');
            title.className = 'font-bold text-sm';
            title.textContent = data.proveedor || 'Proveedor sin identificar';
            const detail = document.createElement('p');
            detail.className = 'text-xs text-muted mt-1';
            detail.textContent = [
                data.numero_factura ? `Factura ${data.numero_factura}` : null,
                data.fecha || null,
                data.total ? `${data.total} ${data.moneda || ''}`.trim() : null,
                doc.tipo === 'pdf' ? 'PDF' : 'Imagen',
                String(doc.creado || '').slice(0, 10),
            ].filter(Boolean).join(' · ');
            info.append(title, detail);
            const estado = document.createElement('div');
            estado.className = 'flex items-center gap-2';
            const tone = doc.estado === 'confirmado'
                ? 'bg-green-100 text-green-700'
                : (doc.estado === 'revisar' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700');
            estado.append(badge(doc.estado, tone));
            if (doc.confianza !== null && doc.confianza !== undefined) {
                estado.append(badge(`${Math.round(Number(doc.confianza) * 100)}%`, 'bg-cream text-muted border border-bord'));
            }
            if (doc.estado === 'revisar' || doc.estado === 'fallido') {
                const review = document.createElement('button');
                review.type = 'button';
                review.className = 'text-sm font-bold text-leaf hover:text-leaf-light';
                review.textContent = 'Revisar';
                review.addEventListener('click', () => {
                    if (busy) return;
                    resetForm();
                    currentId = doc.id;
                    fillForm(doc.resultado || {});
                    setConfidence({ ...(doc.resultado || {}), manual: doc.estado === 'fallido' });
                    $('docConfirm').disabled = false;
                    $('docForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
                estado.append(review);
            }
            card.append(info, estado);
            box.append(card);
        }
    }

    async function loadHistory() {
        try {
            const result = await API.request('empleado', 'list');
            renderHistory(result.documentos || []);
        } catch (error) {
            $('docHistory').textContent = error.message;
        }
    }

    function init() {
        if (!$('docForm')) return;
        $('docFile').addEventListener('change', () => {
            currentId = '';
            fillForm({});
            $('docConfidence').classList.add('hidden');
            $('docConfirm').disabled = true;
            $('docConsent').checked = false;
            $('docAnonimizado').checked = false;
            const file = $('docFile').files[0];
            if (file) preview(file);
        });
        $('docAnalyze').addEventListener('click', analyze);
        $('docForm').addEventListener('submit', confirm);
        $('docCancel').addEventListener('click', resetForm);
        $('docRefresh').addEventListener('click', loadHistory);
        loadHistory();
    }

    return { init, resetForm };
})();

window.Empleado = Empleado;
