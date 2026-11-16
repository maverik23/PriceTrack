/**
 * Navegador de PriceTrack: abre la página de un artículo en un Chromium real y
 * devuelve el HTML ya cargado, para leer el precio de tiendas que bloquean a
 * los programas (Walmart y Sam's con PerimeterX, Amazon).
 *
 * Va con ventana (en la pantalla virtual de xvfb-run): las tiendas detectan y
 * bloquean a los navegadores sin ventana ("headless"). Cada consulta usa una
 * ventana limpia (como incógnito): si la tienda marca unas cookies como
 * sospechosas, no arrastran a las siguientes consultas.
 *
 *   GET /html?url=https://…   → 200 con el HTML (cabecera X-Final-Url) o 502 con el error
 *   GET /health               → ok
 *
 * Solo escucha dentro del stack (sin puerto publicado). Atiende una página a la vez.
 */
import http from 'node:http';
import { chromium } from 'playwright';

const PORT = Number(process.env.PORT || 3000);
const PROXY = process.env.BROWSER_PROXY || null;

let instance = null;

async function browser() {
    if (instance?.isConnected()) return instance;
    instance = await chromium.launch({
        headless: false,
        args: ['--disable-blink-features=AutomationControlled', '--no-first-run', '--no-default-browser-check'],
        ...(PROXY ? { proxy: { server: PROXY } } : {}),
    });
    return instance;
}

/** Espera a que la página tenga el producto (JSON-LD, precio de Amazon) o a que la tienda la bloquee. */
function ready() {
    return location.pathname.startsWith('/blocked')
        || [...document.querySelectorAll('script[type="application/ld+json"]')].some((s) => s.textContent.includes('"Product"'))
        || document.querySelector('#corePrice_feature_div .a-offscreen, #corePriceDisplay_desktop_feature_div .a-offscreen') !== null;
}

let queue = Promise.resolve();

function render(url) {
    const job = queue.then(async () => {
        const context = await (await browser()).newContext({
            locale: 'es-MX',
            timezoneId: process.env.TZ || 'America/Mexico_City',
            viewport: { width: 1366, height: 860 },
        });
        const page = await context.newPage();
        try {
            await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
            await page.waitForFunction(ready, null, { timeout: 15000 }).catch(() => {});
            return { url: page.url(), html: await page.content() };
        } finally {
            await context.close().catch(() => {});
        }
    });
    queue = job.catch(() => {});
    return job;
}

http.createServer(async (req, res) => {
    const { pathname, searchParams } = new URL(req.url, 'http://localhost');
    if (pathname === '/health') return res.end('ok');
    if (pathname !== '/html') return res.writeHead(404).end();

    const target = searchParams.get('url') || '';
    if (!/^https:\/\/[^/]+\.[^/]+/i.test(target)) return res.writeHead(400).end('Solo direcciones https');

    try {
        const { url, html } = await render(target);
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'X-Final-Url': encodeURI(url) }).end(html);
    } catch (e) {
        instance?.close().catch(() => {});
        res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' }).end(String(e?.message || e).split('\n')[0]);
    }
}).listen(PORT, () => console.log(`Navegador de PriceTrack escuchando en :${PORT}`));
