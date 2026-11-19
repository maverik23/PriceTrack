/**
 * Precios en línea fuera de la hoja:
 *
 * - productPrices: "Obtener precios" en la ficha del artículo, tienda por tienda con
 *   avance (POST product-links/{id}/fetch); al terminar recarga para ver los precios.
 * - priceLink: al capturar un precio a mano (formulario de precios, ficha del artículo),
 *   si ese artículo tiene dirección guardada en esa tienda la ofrece y con un toque trae
 *   el precio de hoy (preview: no lo guarda) y llena precio y presentación.
 */
import { createRun, ensureBranches, requestJson, runTasks } from './online-prices';

const FLASH = 'pricetrack:flash';

/** Mensaje para mostrar después de recargar (sessionStorage, si se puede). */
export function flashAfterReload(message) {
    try {
        sessionStorage.setItem(FLASH, message);
    } catch {}
}

export function takeFlash() {
    try {
        const message = sessionStorage.getItem(FLASH);
        sessionStorage.removeItem(FLASH);
        return message;
    } catch {
        return null;
    }
}

export default function productPrices(config) {
    return {
        links: config.links,
        run: createRun(),

        async fetchAll() {
            if (this.run.active) return;
            const skipped = await ensureBranches(this.links.map((l) => l.provider_id));
            const tasks = this.links.map((l) => ({ ...l, label: `${config.product} — ${l.provider}` }));
            await runTasks(this.run, 'Obteniendo precios', tasks, async (task, signal) => {
                if (skipped.includes(task.provider_id)) return { ok: false, message: 'Sin sucursal elegida: no se consultó.' };
                return requestJson('POST', task.fetch, {}, { signal });
            });
            if (this.run.ok && !this.run.failures.length) {
                flashAfterReload(`Precios en línea: ${this.run.ok} ${this.run.ok === 1 ? 'tienda actualizada' : 'tiendas actualizadas'}.`);
                window.location.reload();
            }
        },
        reload() {
            window.location.reload();
        },
    };
}

/** "Actualizar todos los precios" (Artículos): todas las direcciones guardadas, una por una. */
export function bulkPrices(config) {
    return {
        run: createRun(),
        empty: '',

        async start() {
            if (this.run.active) return;
            this.empty = '';
            let links;
            try {
                links = (await requestJson('GET', config.plan)).links;
            } catch (e) {
                this.empty = e.message;
                return;
            }
            if (!links.length) {
                this.empty = 'Ningún artículo tiene dirección en alguna tienda. Pégala en la ficha del artículo («En cada tienda») o en una celda de la hoja.';
                return;
            }
            const skipped = await ensureBranches(links.map((l) => l.provider_id));
            await runTasks(this.run, 'Actualizando todos los precios', links, async (link, signal) => {
                if (skipped.includes(link.provider_id)) return { ok: false, message: 'Sin sucursal elegida: no se consultó.' };
                return requestJson('POST', link.fetch, {}, { signal });
            });
            if (this.run.ok && !this.run.failures.length && !this.run.cancelled) {
                flashAfterReload(`Precios en línea: ${this.run.ok} ${this.run.ok === 1 ? 'precio actualizado' : 'precios actualizados'}.`);
                window.location.reload();
            }
        },
        reload() {
            window.location.reload();
        },
    };
}

export function priceLink(config) {
    return {
        links: config.links || {},
        product: String(config.product ?? ''),
        provider: String(config.provider ?? ''),
        busy: false,
        status: '',
        error: '',

        init() {
            const form = this.$el.closest('form') || this.$el;
            form.querySelector('[name="product_id"]')?.addEventListener('change', (e) => {
                this.product = e.target.value;
                this.status = this.error = '';
            });
            const provider = form.querySelector('[name="provider_id"]');
            if (provider) {
                this.provider = provider.value || this.provider;
                provider.addEventListener('change', (e) => {
                    this.provider = e.target.value;
                    this.status = this.error = '';
                });
            }
        },
        get link() {
            return this.links?.[this.product]?.[this.provider] ?? null;
        },
        async fill() {
            if (!this.link || this.busy) return;
            this.busy = true;
            this.error = this.status = '';
            try {
                const data = await requestJson('POST', this.link.fetch, { preview: true });
                if (!data.ok) throw new Error(data.message);
                const form = this.$el.closest('form');
                const set = (name, value) => {
                    const field = form.querySelector(`[name="${name}"]:not([type="hidden"])`);
                    if (!field) return;
                    if (field.type === 'checkbox') field.checked = !!value;
                    else field.value = value;
                };
                set('price', Number(data.price).toFixed(2));
                set('pack_qty', data.bulk ? 1 : data.pack_qty);
                set('size', data.size);
                set('unit', data.unit);
                set('bulk', data.bulk);
                form.dispatchEvent(new Event('input', { bubbles: true }));
                this.status = `Precio de hoy en ${data.provider}: ${data.message}. Revisa y guarda.`;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.busy = false;
            }
        },
    };
}
