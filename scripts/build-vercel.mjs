// Build Output API v3: publica únicamente archivos públicos y una ruta a PHP.
// BACKEND_ORIGIN es una URL pública, nunca un token. Las claves de IA y MySQL
// pertenecen al servidor externo y no se necesitan durante este build.
import { cp, mkdir, readdir, rm, writeFile } from 'node:fs/promises';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export function backendOrigin(value) {
    let url;
    try { url = new URL(value); } catch { throw new Error('Define BACKEND_ORIGIN con la URL HTTPS del backend.'); }
    if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== '/') {
        throw new Error('BACKEND_ORIGIN debe ser un origen HTTPS sin ruta, credenciales ni parámetros.');
    }
    if (['localhost', '127.0.0.1', '[::1]'].includes(url.hostname) || url.hostname.endsWith('.local')) {
        throw new Error('Vercel necesita un backend público; localhost no es accesible.');
    }
    return url.origin;
}

export function routing(origin) {
    return { version: 3, routes: [
        // Conservar consulta, método, cuerpo y cookies mediante el proxy nativo.
        // No se usa una Function intermedia para cargar documentos.
        { src: '^/api/index\\.php$', dest: `${origin}/api/index.php`,
          headers: { 'Cache-Control': 'private, no-store', 'Vercel-CDN-Cache-Control': 'no-store' } },
        { src: '^/api(?:/.*)?$', status: 404 },
        { src: '^/(.*)$', headers: { 'X-Content-Type-Options': 'nosniff',
          'Referrer-Policy': 'strict-origin-when-cross-origin', 'X-Frame-Options': 'DENY' }, continue: true },
        { src: '^/$', dest: '/index.html' },
        { handle: 'filesystem' },
        { src: '^/.*$', status: 404 }
    ] };
}

export async function build(root, origin) {
    // Destino fijo resuelto dentro del proyecto; no se borra ninguna ruta del usuario.
    const output = resolve(root, '.vercel/output');
    if (!output.startsWith(resolve(root) + '/'.replace('/', process.platform === 'win32' ? '\\' : '/'))) {
        throw new Error('Directorio de salida fuera del proyecto.');
    }
    await rm(output, { recursive: true, force: true });
    const pub = join(output, 'static');
    await mkdir(pub, { recursive: true });
    const pages = ['index.html', 'login.html', 'cliente.html', 'admin.html', 'empleado.html'];
    for (const name of pages) await cp(join(root, name), join(pub, name));
    // Lista positiva: nunca copiar api/, engine/, sql/, .env o documentación.
    const allowed = new Set(['.js', '.css', '.svg', '.jpg', '.jpeg', '.png', '.webp', '.gif', '.ico', '.woff', '.woff2', '.ttf', '.eot']);
    async function assets(source, dest) {
        await mkdir(dest, { recursive: true });
        for (const entry of await readdir(source, { withFileTypes: true })) {
            if (entry.name.startsWith('.') || entry.isSymbolicLink()) continue;
            if (entry.isDirectory()) await assets(join(source, entry.name), join(dest, entry.name));
            else if (allowed.has(entry.name.slice(entry.name.lastIndexOf('.')).toLowerCase())) {
                await cp(join(source, entry.name), join(dest, entry.name));
            }
        }
    }
    for (const name of ['css', 'js', 'img', 'vendor']) await assets(join(root, name), join(pub, name));
    await writeFile(join(output, 'config.json'), JSON.stringify(routing(origin), null, 2));
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
    await build(root, backendOrigin(process.env.BACKEND_ORIGIN));
    console.log('Vercel: archivos públicos y proxy preparados; no se han publicado.');
}
