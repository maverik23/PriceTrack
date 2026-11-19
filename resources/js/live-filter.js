/**
 * Filtros en vivo: los formularios GET con `data-live-filter` se aplican solos al escribir
 * (con una pausa corta) o al elegir algo, sin botón "Buscar" ni Enter.
 *
 * - Si la pantalla tiene una zona `[data-live-results]` (con id), se pide la página con los
 *   filtros y solo se reemplaza esa zona: no se recarga, el campo conserva el foco y no se
 *   corta lo que esté corriendo. Los enlaces dentro de esa zona (pestañas, etiquetas,
 *   paginación) y los de un elemento `[data-live-links]` también se cargan así.
 * - Sin zona (reportes), se navega a la página con los filtros.
 * - Si el servidor redirige a otra pantalla (un código de barras exacto lleva al artículo), se va ahí.
 * - Al terminar se avisa con el evento `live-filter:updated` (app.js redibuja las gráficas).
 */
const TYPING = 'input[type=text], input[type=search], input:not([type])';
const PICKING = 'select, input[type=date], input[type=month], input[type=number], input[type=checkbox], input[type=radio]';

let controller = null;
let timer = null;

function urlFor(form) {
    const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
    url.search = '';
    for (const [name, value] of new FormData(form)) {
        if (typeof value === 'string' && value.trim() !== '') url.searchParams.append(name, value.trim());
    }
    return url.toString();
}

function regions(doc = document) {
    return [...doc.querySelectorAll('[data-live-results][id]')];
}

/** Lleva a los formularios de filtro los valores de la página nueva (menos el campo donde se escribe). */
function syncForms(doc) {
    for (const form of document.querySelectorAll('form[data-live-filter]')) {
        const fresh = form.id ? doc.getElementById(form.id) : doc.querySelector('form[data-live-filter]');
        if (!fresh) continue;
        for (const field of form.elements) {
            if (!field.name || field === document.activeElement || field.type === 'submit') continue;
            const match = fresh.elements.namedItem(field.name);
            if (!match || match instanceof RadioNodeList) continue;
            if (field.type === 'checkbox' || field.type === 'radio') field.checked = match.checked;
            else field.value = match.value;
        }
        // Lo que cambia según el filtro dentro del formulario (botón "Limpiar")
        for (const part of form.querySelectorAll('[data-live-part][id]')) {
            const next = fresh.querySelector(`#${CSS.escape(part.id)}`);
            if (next) part.replaceWith(document.importNode(next, true));
        }
    }
}

export async function loadFiltered(url, { push = false } = {}) {
    const current = regions();
    if (!current.length) {
        window.location.assign(url);
        return;
    }

    controller?.abort();
    controller = new AbortController();
    current.forEach((el) => el.setAttribute('aria-busy', 'true'));
    try {
        const res = await fetch(url, { signal: controller.signal, headers: { Accept: 'text/html', 'X-Live-Filter': '1' } });
        if (res.redirected && new URL(res.url).pathname !== window.location.pathname) {
            window.location.assign(res.url);
            return;
        }
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');

        for (const el of regions()) {
            const next = doc.getElementById(el.id);
            if (next) el.replaceWith(document.importNode(next, true));
        }
        syncForms(doc);
        const target = res.redirected ? res.url : url;
        if (push) window.history.pushState({ live: true }, '', target);
        else window.history.replaceState({ live: true }, '', target);
        window.dispatchEvent(new CustomEvent('live-filter:updated'));
    } catch (e) {
        if (e.name === 'AbortError') return;
        // Si algo falla, como antes: cargar la página completa
        window.location.assign(url);
    } finally {
        regions().forEach((el) => el.removeAttribute('aria-busy'));
    }
}

function liveLink(event) {
    const a = event.target.closest('a[href]');
    if (!a || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (a.target && a.target !== '_self') return;
    if (!a.closest('[data-live-results], [data-live-links]') || a.closest('[data-live-off]')) return;
    const url = new URL(a.href, window.location.href);
    // Solo la misma pantalla con otros filtros (pestañas, etiquetas, páginas)
    if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;
    event.preventDefault();
    clearTimeout(timer);
    loadFiltered(url.toString(), { push: true });
}

export function initLiveFilters() {
    for (const form of document.querySelectorAll('form[data-live-filter]')) {
        form.addEventListener('input', (e) => {
            if (!e.target.matches(TYPING)) return;
            clearTimeout(timer);
            timer = setTimeout(() => loadFiltered(urlFor(form)), 350);
        });
        form.addEventListener('change', (e) => {
            if (!e.target.matches(PICKING)) return;
            clearTimeout(timer);
            loadFiltered(urlFor(form));
        });
        // Enter (o escanear un código) aplica de inmediato
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            clearTimeout(timer);
            loadFiltered(urlFor(form));
        });
    }
    document.addEventListener('click', liveLink);
    window.addEventListener('popstate', (e) => {
        if (e.state?.live || regions().length) loadFiltered(window.location.href);
    });
}
