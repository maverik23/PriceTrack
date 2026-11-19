/**
 * Precios en línea desde el navegador.
 *
 * - requestJson(): fetch JSON con CSRF. Si el servidor responde 409 con `needs_branch`
 *   (la tienda cambia el precio por sucursal y falta el código postal o la sucursal,
 *   App\Services\StoreBranches), abre la ventana para elegirla y reintenta una vez.
 * - pickBranch(): abre la ventana global <x-branch-picker /> y espera a que se guarde.
 * - ensureBranches(): antes de una consulta larga, pregunta una sola vez por cada proveedor
 *   al que le falte la sucursal (los proveedores y lo que falta vienen del layout, #branch-chains).
 * - createRun() / runTasks(): avance de una operación larga, tarea por tarea, cancelable,
 *   con lo que salió bien, lo que falló y por qué (lo pinta <x-progress-panel />).
 */
import Alpine from 'alpinejs';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

/** Proveedores de alguna cadena y qué les falta (del servidor, en el layout): id del proveedor => info. */
export function branchChains() {
    try {
        return JSON.parse(document.getElementById('branch-chains')?.textContent || '{}');
    } catch {
        return {};
    }
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
 * Pregunta, una vez y antes de empezar, la sucursal de cada uno de esos proveedores al que
 * le falte. Devuelve los ids de los proveedores que quedaron sin elegir (se cancelaron).
 */
export async function ensureBranches(providerIds) {
    const chains = branchChains();
    const needed = [...new Set(providerIds.map(Number))].filter((id) => chains[id]?.missing);
    const skipped = [];
    for (const id of needed) {
        const ok = await pickBranch(chains[id]);
        if (ok) chains[id].missing = null;
        else skipped.push(id);
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
        throw new RequestError(`Sin sucursal de ${data.needs_branch.provider || data.needs_branch.name}: no se consultó.`, data, 409);
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
                const params = new URLSearchParams({ chain: this.info.chain, postal_code: this.postal, provider_id: this.info.provider_id });
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
                const name = this.branches.find((b) => b.id === this.branch)?.label || null;
                await requestJson('PUT', urls.save, {
                    provider_id: this.info.provider_id, chain: this.info.chain, postal_code: this.postal, branch: this.branch, branch_name: name,
                }, { retry: false });
                const chains = branchChains();
                const el = document.getElementById('branch-chains');
                if (el) {
                    // Otro CP: los demás proveedores que eligen de una lista se vuelven a preguntar
                    for (const [id, info] of Object.entries(chains)) {
                        if (Number(id) === Number(this.info.provider_id)) Object.assign(info, { missing: null, postal_code: this.postal });
                        else if (info.postal_code !== this.postal) Object.assign(info, { postal_code: this.postal, missing: info.listable && info.ask ? 'branch' : info.missing });
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

// ---------- Actualización de precios en el servidor ----------

/**
 * Avance de "Actualizar todos los precios", que corre en el servidor (App\Jobs\ProcessPriceRun):
 * vive en el layout (<x-price-run-status />), así que se ve en cualquier pantalla y sigue al
 * recargar. Pregunta el avance cada 2 s mientras corre. El objeto `run` tiene la forma de
 * createRun() para usar <x-progress-panel />.
 */
export function priceRunStatus(config) {
    return {
        run: null,
        timer: null,
        error: '',

        init() {
            const self = this;
            this.run = {
                id: null, url: null, active: false, finished: false, stalled: false, title: '', total: 0, done: 0, ok: 0, failures: [], current: '', cancelled: false,
                get percent() {
                    return this.total ? Math.round((this.done / this.total) * 100) : 0;
                },
                cancel() {
                    self.cancel();
                },
                dismiss() {
                    self.dismiss();
                },
            };
            if (config.run) this.apply(config.run);
            window.addEventListener('price-run-started', (e) => this.apply(e.detail));
        },
        apply(state) {
            Object.assign(this.run, state);
            clearTimeout(this.timer);
            if (this.run.active) this.timer = setTimeout(() => this.poll(), 2000);
        },
        async poll() {
            try {
                this.apply((await requestJson('GET', this.run.url, null, { retry: false })).run);
                this.error = '';
            } catch (e) {
                this.error = e.message;
                this.timer = setTimeout(() => this.poll(), 5000);
            }
        },
        async cancel() {
            this.run.cancelled = true;
            try {
                this.apply((await requestJson('POST', `${this.run.url}/cancel`, {}, { retry: false })).run);
            } catch (e) {
                this.error = e.message;
            }
        },
        async resume() {
            try {
                this.apply((await requestJson('POST', `${this.run.url}/resume`, {}, { retry: false })).run);
            } catch (e) {
                this.error = e.message;
            }
        },
        dismiss() {
            Object.assign(this.run, { finished: false, failures: [] });
        },
        reload() {
            window.location.reload();
        },
    };
}
