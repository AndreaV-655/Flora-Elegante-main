// El modelo propone; la persona confirma. Nunca inyectar texto de la IA como HTML.
document.addEventListener('DOMContentLoaded', () => {
    const area = document.getElementById('chatInputArea');
    if (!area) return;
    const tools = document.createElement('details');
    tools.className = 'text-sm mt-2';
    const summary = document.createElement('summary');
    summary.textContent = 'Foto de un arreglo · Cupones'; tools.append(summary);
    const notice = document.createElement('p'); notice.className = 'text-xs my-2';
    notice.textContent = 'Sube solo fotos de flores, sin personas ni documentos. La imagen se guarda con acceso restringido y, en la versión en la nube, se envía a Groq para analizarla. Puedes solicitar su eliminación al administrador.';
    tools.append(notice);
    const consent = document.createElement('input'); consent.type = 'checkbox';
    const label = document.createElement('label');
    label.append(consent, ' Autorizo guardar y analizar esta foto para buscar un arreglo.'); tools.append(label);
    const file = document.createElement('input');
    file.type = 'file'; file.accept = 'image/jpeg,image/png,image/webp';
    file.setAttribute('aria-label', 'Foto del arreglo (máximo 5 MB)'); file.className = 'block w-full my-2';
    tools.append(file); area.append(tools);
    const messages = document.getElementById('chatMessages');
    function say(text) {
        const box = document.createElement('div'); box.className = 'p-3 rounded-xl bg-white border border-bord text-sm';
        box.setAttribute('role', 'status'); box.textContent = text;
        messages.append(box); messages.scrollTop = messages.scrollHeight; return box;
    }
    file.addEventListener('change', async () => {
        const photo = file.files[0]; if (!photo) return;
        if (!Auth.session()) { say('Inicia sesión para reconocer una foto.'); return; }
        if (!consent.checked) { say('Debes autorizar el procesamiento de la foto.'); file.value = ''; return; }
        if (photo.size > 5 * 1024 * 1024) { say('La foto supera 5 MB. Reduce su tamaño.'); file.value = ''; return; }
        file.disabled = true;
        const status = say('Analizando tu foto. Puede tardar hasta un minuto y medio…');
        try {
            const encoded = await new Promise((resolve, reject) => {
                const reader = new FileReader(); reader.onload = () => resolve(String(reader.result).split(',')[1]);
                reader.onerror = reject; reader.readAsDataURL(photo);
            });
            const result = await API.request('vision', 'analyze', { imagen: encoded, consentimiento: true }, 'POST');
            if (!result.producto) { status.textContent = 'No encontré una coincidencia confiable. Prueba otra foto o consulta el catálogo.'; return; }
            status.textContent = `${result.producto.nombre} · ${Data.formatPrecio(Number(result.producto.precio))} · Stock: ${result.producto.stock}. Confianza estimada por la IA: ${Math.round(result.confianza * 100)}%. ${result.confianza < .8 ? 'Confianza baja: revisa cuidadosamente. ' : ''}${result.explicacion} Confirma si corresponde al arreglo que buscas.`;
            const confirm = document.createElement('button'); confirm.type = 'button';
            confirm.className = 'block mt-2 p-2 rounded bg-forest text-white'; confirm.textContent = 'Confirmar y añadir al carrito';
            confirm.addEventListener('click', async () => {
                confirm.disabled = true;
                try {
                    const confirmed = await API.request('vision', 'confirm', { id: result.id }, 'POST');
                    await API.cargarCatalogo();
                    if (Number(confirmed.producto.stock) <= 0) { say('El arreglo está agotado. Se notificó a la empresa.'); return; }
                    Cart.add(Number(confirmed.producto.id)); say('Arreglo confirmado. Revisa tu carrito para finalizar el pedido.'); confirm.remove();
                } catch (error) { say(error.message); confirm.disabled = false; }
            });
            status.append(confirm);
        } catch (error) { status.textContent = error.message; }
        finally { file.disabled = false; file.value = ''; }
    });
    function couponField(parent) {
        const label = document.createElement('label'); label.className = 'block text-sm my-2'; label.textContent = 'Cupón personal (opcional)';
        const input = document.createElement('input'); input.className = 'fe-coupon border rounded p-2 w-full'; input.maxLength = 40;
        input.value = sessionStorage.getItem('feCoupon') || '';
        input.addEventListener('input', () => {
            const value = input.value.trim().toUpperCase(); sessionStorage.setItem('feCoupon', value);
            document.querySelectorAll('.fe-coupon').forEach(other => { if (other !== input) other.value = value; });
        }); label.append(input); parent.append(label);
    }
    couponField(tools);
    const checkout = document.getElementById('checkoutBtn');
    if (checkout) { const holder = document.createElement('div'); checkout.before(holder); couponField(holder); }
    const marketing = document.createElement('input'); marketing.type = 'checkbox';
    const pref = document.createElement('label'); pref.className = 'block my-2';
    pref.append(marketing, ' Quiero recibir por correo cupones en aniversarios de mis compras.'); tools.append(pref);
    API.request('preferences', 'get').then(r => { marketing.checked = r.marketing; }).catch(() => {});
    marketing.addEventListener('change', async () => {
        marketing.disabled = true;
        try { await API.request('preferences', 'save', { marketing: marketing.checked }, 'POST'); say('Preferencia de recordatorios guardada.'); }
        catch (error) { marketing.checked = !marketing.checked; say(error.message); }
        finally { marketing.disabled = false; }
    });
});
