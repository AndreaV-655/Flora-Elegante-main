// Operación del motor; el servidor vuelve a comprobar el rol de administrador.
document.addEventListener('DOMContentLoaded', async () => {
    if (document.body.dataset.page !== 'admin') return;
    const section = document.createElement('section'); section.className = 'max-w-6xl mx-auto p-6 my-8 bg-white rounded-xl border';
    const title = document.createElement('h2'); title.className = 'text-xl font-bold'; title.textContent = 'Automatizaciones y costos'; section.append(title);
    const content = document.createElement('div'); section.append(content);
    const refresh = document.createElement('button'); refresh.textContent = 'Actualizar estado'; refresh.className = 'p-2 border rounded my-3';
    section.append(refresh); document.body.append(section);
    async function render() {
        try {
            const data = await API.request('automation', 'list'); content.replaceChildren();
            const note = document.createElement('p'); note.textContent = 'El costo se copia en cada nueva venta. Las ventas antiguas sin costo no permiten calcular utilidad. Los avisos fallidos permiten reintento manual.'; content.append(note);
            for (const product of data.productos) {
                const row = document.createElement('div'); row.className = 'flex gap-2 items-center my-2';
                const label = document.createElement('label'); label.textContent = product.nombre + ' · Costo COP: ';
                const input = document.createElement('input'); input.type = 'number'; input.min = '0'; input.step = '.01'; input.value = product.costo ?? ''; input.className = 'border rounded p-1'; label.append(input);
                const save = document.createElement('button'); save.textContent = 'Guardar'; save.className = 'border rounded p-1';
                save.onclick = async () => { if (input.value === '' || !input.checkValidity()) return; try { await API.request('automation', 'cost', { id: product.id, costo: input.value }, 'POST'); save.textContent = 'Guardado'; } catch (e) { showToast(e.message, 'error'); } };
                row.append(label, save); content.append(row);
            }
            const list = document.createElement('div'); list.className = 'max-h-96 overflow-auto mt-4';
            for (const job of data.jobs) {
                const row = document.createElement('p'); row.className = 'border-t py-2 text-sm'; row.textContent = `${job.clave} · ${job.estado} · intentos: ${job.intentos} · ${job.resultado || 'En espera'}`;
                if (job.estado === 'fallido') {
                    const retry = document.createElement('button'); retry.textContent = 'Reintentar'; retry.className = 'border p-1 ml-2';
                    retry.onclick = async () => { try { await API.request('automation', 'retry', { id: job.id }, 'POST'); await render(); } catch (e) { showToast(e.message, 'error'); } }; row.append(retry);
                } list.append(row);
            } content.append(list);
            const logs = document.createElement('details'); logs.className = 'mt-4';
            const logsTitle = document.createElement('summary'); logsTitle.textContent = 'Ejecuciones de automatización'; logs.append(logsTitle);
            (data.logs || []).slice(0, 30).forEach(item => {
                const line = document.createElement('p'); line.className = 'text-xs py-1';
                line.textContent = `${item.fecha_inicio} · ${item.nombre_flujo} · ${item.estado} · ${item.resultado_o_error || (item.fecha_fin ? 'En curso' : 'Pendiente')}`;
                logs.append(line);
            }); content.append(logs);
            const audit = document.createElement('details'); const heading = document.createElement('summary'); heading.textContent = 'Auditoría reciente'; audit.append(heading);
            data.audit.forEach(item => { const line = document.createElement('p'); line.className = 'text-xs py-1'; line.textContent = `${item.fecha} · ${item.accion} · ${item.referencia} · ${item.resultado}`; audit.append(line); }); content.append(audit);
        } catch (e) { content.textContent = e.message; }
    }
    refresh.onclick = render; await render();
});
