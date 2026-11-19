/**
 * Precios en línea desde el navegador.
 *
 * - requestJson(): fetch JSON con CSRF. Si el servidor responde 409 con `needs_branch`
 *   (la tienda cambia el precio por sucursal y falta el código postal o la sucursal,
 *   App\Services\StoreBranches), abre la ventana para elegirla y reintenta una vez.
 * - pickBranch(): abre la ventana global <x-branch-picker /> y espera a que se guarde.
 * - ensureBranches(): antes de una consulta larga, pregunta una sola vez por cada cadena
 *   que haga falta (las cadenas y lo que falta vienen del layout, #branch-chains).
 * - createRun() / runTasks(): avance de una operación larga, tarea por tarea, cancelable,
 *   con lo que salió bien, lo que falló y por qué (lo pinta <x-progress-panel />).
 */
import Alpine from 'alpinejs';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

/** Cadenas con precio por sucursal y qué les falta (del servidor, en el layout). */
export function branchChains() {
    try {
        return JSON.parse(document.getElementById('branch-chains')?.textContent || '{}');
    } catch {
        return {};
    }
}

/** La cadena de una dirección, si cambia el precio por sucursal. */
export function chainFor(url) {
    let host = '';
    try {
        host = new URL(url).hostname.toLowerCase();
    } catch {
        return null;
    }
    for (const [key, chain] of Object.entries(branchChains())) {
        if (host === chain.host || host.endsWith('.' + chain.host)) return key;
    }
    return null;
}

let pending = null;

/** Abre la ventana de sucursal; resuelve true si se guardó, false si se canceló. */
export function pickBranch(info) {
    if (pending) return pending;
    pending = new Promise((resolve) => {
        window.dispatchEvent(new CustomEvent('pick-branch', { detail: { ...info, resolve } }));
    }).finally(() => {
        pending = null;
    });
    return pending;
}

/**
 * Pregunta, una vez y antes de empezar, la sucursal de cada cadena de esas direcciones
 * a la que le falte. Devuelve las cadenas que quedaron sin elegir (se cancelaron).
 */
export async function ensureBranches(urls) {
    const chains = branchChains();
    const needed = [...new Set(urls.map(chainFor).filter(Boolean))].filter((key) => chains[key]?.missing);
    const skipped = [];
    for (const key of needed) {
        const ok = await pickBranch({ chain: key, name: chains[key].name, postal_code: chains[key].postal_code, missing: chains[key].missing });
        if (ok) chains[key].missing = null;
        else skipped.push(key);
    }
    return skipped;
}

export class RequestError extends Error {
    constructor(message, data = {}, status = 0) {
        super(message);
        this.data = data;
        this.status = status;
    }
}

/** fetch JSON; con 409 + needs_branch pide la sucursal y reintenta una vez. */
export async function requestJson(method, url, body, { retry = true, signal } = {}) {
    let res;
    try {
        res = await fetch(url, {
            method,
            signal,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch (e) {
        if (e.name === 'AbortError') throw e;
        throw new RequestError('Sin conexión con PriceTrack. Revisa tu red e intenta de nuevo.');
    }
    const data = await res.json().catch(() => ({}));
    if (res.status === 409 && data.needs_branch && retry) {
        if (await pickBranch(data.needs_branch)) return requestJson(method, url, body, { retry: false, signal });
        throw new RequestError(`Sin sucursal de ${data.needs_branch.name}: no se consultó.`, data, 409);
    }
    if (!res.ok) {
        const message = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || (res.status >= 500 ? 'Error del servidor; intenta de nuevo.' : 'No se pudo completar.');
        throw new RequestError(message, data, res.status);
    }
    return data;
}

// ---------- Avance de operaciones largas ----------

/** Estado de una operación larga (reactivo: lo pinta <x-progress-panel />). */
export function createRun() {
    return Alpine.reactive({
        active: false,
        finished: false,
        title: '',
        total: 0,
        done: 0,
        ok: 0,
        failures: [],
        current: '',
        cancelled: false,
        controller: null,
        get percent() {
            return this.total ? Math.round((this.done / this.total) * 100) : 0;
        },
        cancel() {
            this.cancelled = true;
            this.controller?.abort();
        },
        dismiss() {
            this.finished = false;
            this.failures = [];
        },
    });
}

/**
 * Corre las tareas una por una (las consultas con el navegador de PriceTrack tardan
 * varios segundos cada una). `worker(task, signal)` devuelve { ok, message }.
 * Cada tarea: { label, ... }. Al cancelar se detiene después de la tarea en curso.
 */
export async function runTasks(run, title, tasks, worker) {
    Object.assign(run, { active: true, finished: false, title, total: tasks.length, done: 0, ok: 0, failures: [], current: '', cancelled: false });
    for (const task of tasks) {
        if (run.cancelled) break;
        run.current = task.label;
        run.controller = new AbortController();
        try {
            const result = await worker(task, run.controller.signal);
            if (result?.ok === false) run.failures.push({ label: task.label, message: result.message || 'No se pudo.' });
            else run.ok++;
        } catch (e) {
            if (e.name === 'AbortError') break;
            run.failures.push({ label: task.label, message: e.message || 'No se pudo.' });
        }
        run.done++;
    }
    Object.assign(run, { active: false, finished: true, current: '', controller: null });
    return run;
}

// ---------- Ventana para elegir la sucursal ----------

export function branchPicker(urls) {
    return {
        open: false,
        info: null,
        resolve: null,
        postal: '',
        branches: [],
        branch: 'auto',
        loading: false,
        saving: false,
        error: '',
        searched: '',

        show(detail) {
            this.info = detail;
            this.resolve = detail.resolve;
            this.postal = detail.postal_code || '';
            this.branches = [];
            this.branch = 'auto';
            this.error = '';
            this.searched = '';
            this.open = true;
            this.$nextTick(() => {
                const field = document.getElementById(this.postal ? 'branch-auto' : 'branch-postal');
                field?.focus();
            });
            if (/^\d{5}$/.test(this.postal)) this.search();
        },
        close(saved = false) {
            this.open = false;
            this.resolve?.(saved);
            this.resolve = null;
        },
        onPostal() {
            this.postal = this.postal.replace(/\D/g, '').slice(0, 5);
            if (this.postal.length === 5 && this.postal !== this.searched) this.search();
        },
        async search() {
            if (!/^\d{5}$/.test(this.postal)) {
                this.error = 'Escribe un código postal de 5 dígitos.';
                return;
            }
            this.loading = true;
            this.error = '';
            this.searched = this.postal;
            try {
                const params = new URLSearchParams({ chain: this.info.chain, postal_code: this.postal });
                const data = await requestJson('GET', `${urls.branches}?${params}`, null, { retry: false });
                this.branches = data.branches;
                this.branch = data.selected && data.selected !== 'auto' && data.branches.some((b) => b.id === data.selected) ? data.selected : 'auto';
                if (!data.branches.length) this.error = `No encontré sucursales de ${this.info.name} cerca de ${this.postal}. Puedes dejarla en automática.`;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },
        async save() {
            if (!/^\d{5}$/.test(this.postal)) {
                this.error = 'Escribe un código postal de 5 dígitos.';
                return;
            }
            this.saving = true;
            this.error = '';
            try {
                await requestJson('PUT', urls.save, { chain: this.info.chain, postal_code: this.postal, branch: this.branch }, { retry: false });
                const chains = branchChains();
                const el = document.getElementById('branch-chains');
                if (el && chains[this.info.chain]) {
                    // Otro CP: las demás cadenas se vuelven a preguntar
                    for (const [key, chain] of Object.entries(chains)) {
                        if (key === this.info.chain) Object.assign(chain, { missing: null, postal_code: this.postal });
                        else if (chain.postal_code !== this.postal) Object.assign(chain, { missing: 'branch', postal_code: this.postal });
                    }
                    el.textContent = JSON.stringify(chains);
                }
                this.close(true);
            } catch (e) {
                this.error = e.message;
            } finally {
                this.saving = false;
            }
        },
    };
}
