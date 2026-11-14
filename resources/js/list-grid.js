/**
 * Tabla tipo Excel para una lista de compra.
 *
 * Filas = artículos, columnas = proveedores. Cada celda muestra el último precio
 * guardado de ese proveedor; al cambiarlo se registra como precio nuevo con la
 * fecha de la lista. Los cálculos (precio por unidad, más barato, totales)
 * replican los del backend: App\Models\Price, ShoppingListItem y Product.
 *
 * Cantidad = lo que necesitas, en presentaciones habituales del artículo (o
 * escrita con unidad: "610 g"). Lo pagado en una tienda = lo justo si vende a
 * granel, o los paquetes completos que cubren esa cantidad (Price::costFor()).
 */

const UNIT_ALIASES = {
    ml: 'ml', mililitro: 'ml', mililitros: 'ml',
    l: 'l', lt: 'l', lts: 'l', litro: 'l', litros: 'l',
    g: 'g', gr: 'g', grs: 'g', gramo: 'g', gramos: 'g',
    kg: 'kg', kgs: 'kg', kilo: 'kg', kilos: 'kg',
    pza: 'item', pzas: 'item', pz: 'item', pieza: 'item', piezas: 'item', item: 'item', items: 'item', u: 'item', un: 'item', und: 'item',
};

// Tienda que vende "a granel" sin decir cuánto: por kg, por L o por pieza
const BULK_UNIT = { volume: 'l', mass: 'kg', count: 'item' };
// Price::PACK_TOLERANCE: si falta menos del 10 % de un paquete no se cuenta otro (946 ml ≈ 1 L)
const PACK_TOLERANCE = 0.1;

const money = (v) => (v < 0 ? '-$' : '$') + Math.abs(Number(v)).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const plain = (v) => String(Number(Number(v).toFixed(3)));
const cents = (v) => Math.round(v * 100) / 100;

/** Paquetes completos para tener `need` (ml, g, pza) con paquetes de `base`. Igual que Price::packsFor(). */
const packs = (need, base) => Math.max(1, Math.ceil(Math.round((need / base - PACK_TOLERANCE) * 1e6) / 1e6));
const norm = (s) => (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

/** "$1,234.50" → 1234.5 · "23,5" → 23.5 */
function parseDecimal(input) {
    let s = (input ?? '').toString().replace(/[$\s]/g, '');
    if (s === '') return null;
    s = /,\d{3}(?!\d)/.test(s) && !/,\d{1,2}$/.test(s) ? s.replace(/,/g, '') : s.replace(',', '.');
    const n = Number(s);
    return Number.isFinite(n) ? n : NaN;
}

/**
 * "6 x 1 L" → {pack: 6, size: 1, unit: 'l', bulk: false} · "345" → usa la unidad del artículo
 * A granel: "por kg", "/kg", "por 100 g", "a granel" → {pack: 1, size: 1, unit: 'kg', bulk: true}
 */
function parsePresentation(input, productUnit, units) {
    let s = norm(input).replace(/,/g, '.');
    const bulk = /granel/.test(s) || /^(por\b|\/)/.test(s);
    if (bulk) s = s.replace(/(a )?granel/, '').replace(/^(por\b|\/)/, '').trim();
    const m = s.match(/^(?:(\d+)\s*[x×*]\s*)?(\d+(?:\.\d+)?)?\s*([a-zñ]*)\.?$/);
    if (!m || (!m[2] && !bulk) || (bulk && m[1])) return null;
    const dimension = units[productUnit].dimension;
    const pack = m[1] ? parseInt(m[1], 10) : 1;
    const size = m[2] ? parseFloat(m[2]) : 1;
    const unit = m[3] ? UNIT_ALIASES[m[3]] : bulk && !m[2] ? BULK_UNIT[dimension] : productUnit;
    if (!unit || !(size > 0) || pack < 1) return null;
    if (units[unit].dimension !== dimension) return null;
    return { pack, size, unit, bulk };
}

/** Precio escrito como tarifa: "54/kg" o "25 por 100 g" → precio 54 y presentación "por kg" (a granel). */
function splitRate(draft) {
    const m = (draft.price ?? '').toString().match(/^([^/]*\d[^/]*?)\s*(?:\/|\bpor\b)\s*(.+)$/i);
    return m ? { price: m[1], pres: 'por ' + m[2] } : draft;
}

/** Cantidad para mostrar: "2" presentaciones, o el contenido si es fraccionaria ("610 g", "1.5 kg", "12 pza"). */
function qtyText(row, q, units) {
    const u = units[row.product.unit];
    if (Math.abs(q - Math.round(q)) < 0.0001) return plain(Math.round(q));
    const base = Math.round(q * row.product.size * u.base * 10) / 10;
    if (u.dimension === 'count') return `${plain(base)} pza`;
    const [small, big] = u.dimension === 'mass' ? ['g', 'kg'] : ['ml', 'L'];
    return base >= 1000 ? `${plain(base / 1000)} ${big}` : `${plain(base)} ${small}`;
}

/**
 * Código de barras escrito o escaneado → normalizado como App\Support\Barcode::normalize()
 * (UPC-A de 12 dígitos → EAN-13). null si no parece un código.
 */
function normalizeBarcode(text) {
    let code = (text || '').toString().replace(/[\s-]+/g, '');
    if (!/^\d{6,14}$/.test(code)) return null;
    if (code.length === 12) code = '0' + code;
    else if (code.length === 14 && code[0] === '0') code = code.slice(1);
    return code;
}

/** Busca una presentación dentro del nombre: "Salsa de tomate 345ml" → "345 ml" */
function guessPresentation(name) {
    const m = norm(name).match(/(\d+(?:[.,]\d+)?)\s*(ml|lts?|l|litros?|grs?|g|gramos?|kgs?|kilos?|pzas?|piezas?)\b/);
    return m ? `${m[1].replace(',', '.')} ${m[2]}` : '1 pza';
}

export default function listGrid(config) {
    const rows = config.rows.map((row) => ({ ...row, cells: Array.isArray(row.cells) ? {} : row.cells }));
    const products = config.products.map((p) => ({ ...p, _s: norm([p.name, p.brand, p.category].join(' ')) }));

    const drafts = {};
    const qty = {};
    for (const row of rows) {
        qty[row.id] = qtyText(row, row.quantity, config.units);
        for (const p of config.providers) {
            const c = row.cells[p.id];
            drafts[`${row.id}:${p.id}`] = { price: c ? c.price.toFixed(2) : '', pres: c ? c.presentation : '' };
        }
    }

    return {
        ...config,
        rows,
        products,
        drafts,
        qty,
        errors: {},
        saving: {},
        flashed: {},
        pending: {},
        search: '',
        open: false,
        hi: 0,
        // Objeto (no texto): un :style de texto reemplaza el atributo completo y borra el display:none de x-show
        dropStyle: {},
        creating: null,
        busy: false,
        money,

        // ---------- utilidades ----------
        key(row, pid) {
            return `${row.id}:${pid}`;
        },
        csrf() {
            return document.querySelector('meta[name="csrf-token"]').content;
        },
        async request(method, url, body) {
            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': this.csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body ? JSON.stringify(body) : undefined,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'No se pudo guardar');
            }
            return data;
        },
        syncCell(row, pid) {
            const c = row.cells[pid];
            this.drafts[this.key(row, pid)] = { price: c ? c.price.toFixed(2) : '', pres: c ? c.presentation : '' };
        },
        flash(k) {
            this.flashed[k] = true;
            setTimeout(() => delete this.flashed[k], 900);
        },

        // ---------- cálculos (en vivo, con lo que está escrito) ----------
        /** "2" (presentaciones habituales) o con unidad: "610 g", "1.5 kg" → presentaciones habituales. */
        parseQty(row, text) {
            const m = norm(text).replace(/,/g, '.').match(/^(\d+(?:\.\d+)?|\.\d+)\s*([a-zñ]*)\.?$/);
            if (!m) return NaN;
            const n = parseFloat(m[1]);
            if (!m[2]) return n;
            const unit = UNIT_ALIASES[m[2]];
            if (!unit || this.units[unit].dimension !== this.units[row.product.unit].dimension) return NaN;
            return (n * this.units[unit].base) / this.stdBase(row);
        },
        quantity(row) {
            const q = this.parseQty(row, this.qty[row.id]);
            return q > 0 ? q : row.quantity;
        },
        stdBase(row) {
            return row.product.size * this.units[row.product.unit].base;
        },
        /** Lo que se necesita, en ml / g / pza. */
        need(row) {
            return this.quantity(row) * this.stdBase(row);
        },
        needLabel(row) {
            const q = this.quantity(row);
            if (Math.abs(q - Math.round(q)) >= 0.0001) return qtyText(row, q, this.units);
            return Math.round(q) === 1 ? row.product.label : `${Math.round(q)} × ${row.product.label}`;
        },
        cellValue(row, pid) {
            const draft = this.drafts[this.key(row, pid)];
            if (!draft) return null;
            const d = splitRate(draft);
            const price = parseDecimal(d.price);
            const pres = parsePresentation(d.pres || row.cells[pid]?.presentation || row.product.label, row.product.unit, this.units);
            if (!(price > 0) || !pres) return null;
            const base = pres.pack * pres.size * this.units[pres.unit].base;
            return { price, base, unitPrice: price / base, unit: pres.unit, bulk: pres.bulk };
        },
        /** Lo que cuesta llevarse lo que necesitas con ese precio: lo justo a granel o paquetes completos. */
        costOf(row, v) {
            return cents(v.bulk ? (this.need(row) * v.price) / v.base : packs(this.need(row), v.base) * v.price);
        },
        /** Lo que se recibe con ese precio (ml, g, pza): lo justo a granel o los paquetes completos. */
        boughtBase(row, v) {
            return v.bulk ? this.need(row) : packs(this.need(row), v.base) * v.base;
        },
        selectedValue(row) {
            const s = row.selected;
            return s ? { price: s.price, base: s.base_qty, unitPrice: s.unit_price, bulk: s.bulk } : null;
        },
        /** La tienda donde sale más barato llevarte lo que necesitas (paquetes completos). */
        bestBuy(row) {
            const vals = this.values(row).map((x) => ({ pid: x.pid, cost: this.costOf(row, x.v) }));
            return vals.length ? vals.reduce((a, b) => (b.cost < a.cost ? b : a)) : null;
        },
        values(row) {
            return this.providers.map((p) => ({ pid: p.id, v: this.cellValue(row, p.id) })).filter((x) => x.v);
        },
        cheapest(row) {
            const vals = this.values(row);
            return vals.length ? vals.reduce((a, b) => (b.v.unitPrice < a.v.unitPrice ? b : a)) : null;
        },
        isCheapest(row, pid) {
            const c = this.cheapest(row);
            return c && c.pid === pid && this.values(row).length > 1;
        },
        compareText(row, v) {
            const u = this.units[v.unit];
            return `${money(v.unitPrice * u.compare)}/${u.compare_label}`;
        },
        unitText(row, pid) {
            const v = this.cellValue(row, pid);
            return v ? this.compareText(row, v) : '';
        },
        diffText(row, pid) {
            const c = this.cheapest(row);
            const v = this.cellValue(row, pid);
            if (!c || !v || c.pid === pid) return '';
            return `+${Math.round((v.unitPrice / c.v.unitPrice - 1) * 100)}%`;
        },
        isBought(row, pid) {
            return row.selected && row.selected.provider_id === pid;
        },
        subtotal(row) {
            return row.selected ? this.costOf(row, this.selectedValue(row)) : null;
        },
        estimated(row) {
            const c = this.cheapest(row);
            return c ? this.quantity(row) * this.stdBase(row) * c.v.unitPrice : null;
        },
        isStale(row, pid) {
            const c = row.cells[pid];
            const d = this.drafts[this.key(row, pid)];
            return c && !c.current && d && parseDecimal(d.price) === c.price && (d.pres || c.presentation) === c.presentation;
        },
        cellClass(row, pid) {
            const k = this.key(row, pid);
            const busy = this.saving[k] ? ' opacity-60' : '';
            if (this.errors[k]) return 'bg-red-50' + busy;
            if (this.flashed[k]) return 'bg-emerald-100' + busy;
            if (this.isBought(row, pid)) return 'bg-emerald-50 ring-1 ring-inset ring-emerald-400' + busy;
            if (this.isCheapest(row, pid)) return 'bg-emerald-50/50' + busy;
            return busy;
        },
        providerName(pid) {
            return this.providers.find((p) => p.id === pid)?.name ?? '';
        },
        cellNote(row, pid) {
            const k = this.key(row, pid);
            if (this.errors[k]) return this.errors[k];
            return this.isStale(row, pid) ? row.cells[pid].date_label : '';
        },

        get purchasedCount() {
            return this.rows.filter((r) => r.selected).length;
        },
        get totalPurchased() {
            return this.rows.reduce((s, r) => s + (this.subtotal(r) ?? 0), 0);
        },
        get totalEstimated() {
            return this.rows.reduce((s, r) => s + (this.subtotal(r) ?? this.estimated(r) ?? 0), 0);
        },
        /** Ahorro por unidad: (precio por unidad más caro − el que pagaste) × lo que te llevaste. */
        get savings() {
            return this.rows.reduce((s, r) => {
                if (!r.selected) return s;
                const max = Math.max(r.selected.unit_price, ...this.values(r).map((x) => x.v.unitPrice));
                return s + Math.max(0, (max - r.selected.unit_price) * this.boughtBase(r, this.selectedValue(r)));
            }, 0);
        },
        /** Ahorro real: lo que costaba lo que necesitabas en la tienda más cara (paquetes completos) − lo que pagaste. */
        get realSavings() {
            return this.rows.reduce((s, r) => {
                if (!r.selected) return s;
                const paid = this.subtotal(r);
                return s + Math.max(paid, ...this.values(r).map((x) => this.costOf(r, x.v))) - paid;
            }, 0);
        },
        get progress() {
            return this.rows.length ? Math.round((this.purchasedCount / this.rows.length) * 100) : 0;
        },
        basket(pid) {
            let covered = 0;
            let total = 0;
            for (const r of this.rows) {
                const v = this.cellValue(r, pid);
                if (v) {
                    covered++;
                    total += this.quantity(r) * this.stdBase(r) * v.unitPrice;
                }
            }
            return { covered, total };
        },
        isBestBasket(pid) {
            const full = this.providers.map((p) => ({ pid: p.id, ...this.basket(p.id) })).filter((b) => b.covered === this.rows.length && b.covered > 0);
            if (full.length < 2) return false;
            return full.reduce((a, b) => (b.total < a.total ? b : a)).pid === pid;
        },
        boughtAt(pid) {
            return this.rows.reduce((s, r) => s + (this.isBought(r, pid) ? this.subtotal(r) : 0), 0);
        },

        // ---------- guardar ----------
        commit(row, pid, force = false) {
            const k = this.key(row, pid);
            // Si la celda se está guardando, esperar y volver a evaluar (no descartar lo nuevo)
            if (this.pending[k]) return this.pending[k].then(() => this.commit(row, pid, force));

            const d = this.drafts[k];
            const saved = row.cells[pid];
            delete this.errors[k];

            if ((d.price ?? '').toString().trim() === '') {
                if (saved && saved.list_id === this.listId && saved.current) {
                    return this.track(k, this.removePrice(row, pid, saved));
                }
                this.syncCell(row, pid);
                return;
            }

            const r = splitRate(d);
            const price = parseDecimal(r.price);
            if (!(price > 0)) {
                this.errors[k] = 'Precio no válido';
                return;
            }
            const pres = parsePresentation(r.pres || saved?.presentation || row.product.label, row.product.unit, this.units);
            if (!pres) {
                this.errors[k] = `Presentación no válida (ej. 2 × ${row.product.label} o «por kg»)`;
                return;
            }
            const unchanged = saved && Math.abs(saved.price - price) < 0.005 && saved.pack_qty === pres.pack
                && Math.abs(saved.size - pres.size) < 0.0005 && saved.unit === pres.unit && Boolean(saved.bulk) === pres.bulk;
            if (unchanged && !force) {
                this.syncCell(row, pid);
                return;
            }

            const body = { provider_id: pid, price, pack_qty: pres.pack, size: pres.size, unit: pres.unit, bulk: pres.bulk };
            const sent = { price: d.price, pres: d.pres };
            return this.track(k, this.request('POST', `${this.urls.items}/${row.id}/prices`, body).then((res) => {
                row.cells[pid] = res.price;
                row.price_id = res.item.price_id;
                row.selected = res.item.selected;
                // No pisar lo que se siguió escribiendo mientras se guardaba
                const cur = this.drafts[k];
                if (cur.price === sent.price && cur.pres === sent.pres) this.syncCell(row, pid);
                this.flash(k);
            }));
        },
        track(k, promise) {
            this.saving[k] = true;
            const p = promise
                .catch((e) => { this.errors[k] = e.message; })
                .finally(() => { delete this.saving[k]; delete this.pending[k]; });
            this.pending[k] = p;
            return p;
        },
        async removePrice(row, pid, saved) {
            const res = await this.request('DELETE', `${this.urls.items}/${row.id}/prices/${saved.id}`);
            if (res.price) row.cells[pid] = res.price;
            else delete row.cells[pid];
            row.price_id = res.item.price_id;
            row.selected = res.item.selected;
            this.syncCell(row, pid);
        },
        async toggleBuy(row, pid) {
            const k = this.key(row, pid);
            if (this.pending[k]) await this.pending[k];
            const c = row.cells[pid];
            if (!c) return;
            const priceId = row.price_id === c.id ? null : c.id;
            await this.track(k, this.request('PATCH', `${this.urls.items}/${row.id}`, { price_id: priceId }).then((res) => {
                row.price_id = res.item.price_id;
                row.selected = res.item.selected;
            }));
        },
        async commitQty(row) {
            const q = Math.round(this.parseQty(row, this.qty[row.id]) * 1e4) / 1e4;
            const k = `${row.id}:qty`;
            if (!(q > 0)) {
                this.qty[row.id] = qtyText(row, row.quantity, this.units);
                return;
            }
            if (Math.abs(q - row.quantity) < 0.0001) {
                this.qty[row.id] = qtyText(row, row.quantity, this.units);
                return;
            }
            await this.track(k, this.request('PATCH', `${this.urls.items}/${row.id}`, { quantity: q }).then((res) => {
                row.quantity = res.item.quantity;
                this.qty[row.id] = qtyText(row, row.quantity, this.units);
                this.flash(k);
            }));
        },
        async removeRow(row) {
            if (!confirm(`¿Quitar «${row.product.name}» de la lista?`)) return;
            await this.request('DELETE', `${this.urls.items}/${row.id}`);
            this.rows = this.rows.filter((r) => r.id !== row.id);
        },

        // ---------- teclado ----------
        focusCell(r, c) {
            // Sin $root/$refs: según desde dónde se llame, Alpine puede no resolverlos
            if (r >= this.rows.length) return document.getElementById('grid-search')?.focus();
            const el = document.querySelector(`[data-cell="${r}-${c}"]`);
            if (el) {
                el.focus();
                el.select();
            }
        },
        onCellKey(e, ri, ci, row, pid) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (pid) this.commit(row, pid, true);
                else this.commitQty(row);
                this.focusCell(ri + 1, ci);
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.focusCell(ri + 1, ci);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.focusCell(Math.max(0, ri - 1), ci);
            } else if (e.key === 'Escape') {
                if (pid) this.syncCell(row, pid);
                else this.qty[row.id] = qtyText(row, row.quantity, this.units);
                e.target.blur();
            }
        },

        // ---------- autocompletar ----------
        get results() {
            const q = norm(this.search);
            if (!q) return [];
            const words = q.split(' ');
            const code = normalizeBarcode(q);
            const inList = new Set(this.rows.map((r) => r.product.id));
            return this.products
                .filter((p) => (code ? p.barcode === code : words.every((w) => p._s.includes(w))))
                .slice(0, 8)
                .map((p) => ({ ...p, inList: inList.has(p.id) }));
        },
        get options() {
            const opts = [...this.results];
            const text = this.search.trim();
            const code = normalizeBarcode(text);
            if (code) {
                // Código que no está en tus artículos: buscarlo en internet
                if (!opts.length) opts.push({ create: true, barcode: code, name: code });
            } else if (text && !this.products.some((p) => norm(p.name) === norm(text))) {
                opts.push({ create: true, name: text });
            }
            return opts;
        },
        place() {
            const r = document.getElementById('grid-search').getBoundingClientRect();
            this.dropStyle = { top: `${r.bottom + 4}px`, left: `${r.left}px`, width: `${Math.max(r.width, 320)}px` };
        },
        onSearchInput() {
            this.open = true;
            this.hi = 0;
            this.place();
        },
        onSearchKey(e) {
            const n = this.options.length;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.open = true;
                this.place();
                this.hi = n ? (this.hi + 1) % n : 0;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.hi = n ? (this.hi - 1 + n) % n : 0;
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.options[this.hi]) this.pick(this.options[this.hi]);
            } else if (e.key === 'Escape') {
                this.open = false;
            }
        },
        /** Código leído con la cámara (ya quedó escrito en el buscador). */
        onScanned() {
            this.open = false;
            if (this.options[0]) this.pick(this.options[0]);
        },
        async pick(opt) {
            this.open = false;
            if (opt.create && opt.barcode) return this.createFromBarcode(opt.barcode);
            if (opt.create) {
                // loading explícito: Alpine trata `undefined` en :disabled="creating.loading" como "" y deshabilita
                this.creating = { name: opt.name, pres: guessPresentation(opt.name), loading: false, error: '' };
                this.$nextTick(() => document.getElementById('grid-create-pres')?.select());
                return;
            }
            const existing = this.rows.findIndex((r) => r.product.id === opt.id);
            this.search = '';
            if (existing >= 0) return this.focusCell(existing, 0);
            await this.addRow({ product_id: opt.id });
        },
        /** Código nuevo: buscar sus datos en internet y dejarlos listos para crear el artículo. */
        async createFromBarcode(code) {
            this.search = '';
            const draft = { name: '', pres: '', barcode: code, brand: null, category: null, source: null, loading: true, error: '' };
            this.creating = draft;
            try {
                const res = await this.request('GET', `${this.urls.lookup}?barcode=${encodeURIComponent(code)}`);
                if (this.creating?.barcode !== code) return; // se canceló mientras buscaba
                if (res.product) {
                    // Ya existía (p. ej. se creó en otra pestaña): agregarlo tal cual
                    this.creating = null;
                    return this.addRow({ product_id: res.product.id });
                }
                const s = res.suggestion;
                if (s) {
                    Object.assign(this.creating, { name: s.name, pres: s.presentation || guessPresentation(s.name), brand: s.brand, category: s.category, source: s.source });
                } else {
                    this.creating.error = res.error || 'No lo encontramos en internet: escribe el nombre y la presentación.';
                }
            } catch (e) {
                if (this.creating?.barcode !== code) return;
                this.creating.error = e.message;
            }
            this.creating.loading = false;
            this.$nextTick(() => document.getElementById(this.creating.name ? 'grid-create-pres' : 'grid-create-name')?.select());
        },
        cancelCreate() {
            this.creating = null;
            document.getElementById('grid-search')?.focus();
        },
        async createProduct() {
            const draft = this.creating;
            if (draft.loading) return;
            const name = (draft.name || '').trim();
            if (!name) {
                draft.error = 'Escribe el nombre del artículo';
                return;
            }
            const pres = this.parseAnyPresentation(draft.pres);
            if (!pres || pres.pack !== 1) {
                draft.error = 'Escribe la presentación, ej. 345 ml, 1 L, 680 g, 1 kg o 30 pza';
                return;
            }
            // Cerrar el aviso antes de agregar, para que el foco pase a la fila nueva
            this.creating = null;
            const body = { name, size: pres.size, unit: pres.unit };
            if (draft.barcode) Object.assign(body, { barcode: draft.barcode, brand: draft.brand, category: draft.category });
            const error = await this.addRow(body);
            if (error) {
                this.creating = { ...draft, error };
            }
        },
        parseAnyPresentation(text) {
            const m = norm(text).replace(/,/g, '.').match(/^(?:(\d+)\s*[x×*]\s*)?(\d+(?:\.\d+)?)\s*([a-zñ]+)\.?$/);
            const unit = m && UNIT_ALIASES[m[3]];
            return unit ? { pack: m[1] ? parseInt(m[1], 10) : 1, size: parseFloat(m[2]), unit } : null;
        },
        async addRow(body) {
            this.busy = true;
            try {
                const res = await this.request('POST', this.urls.items, body);
                if (res.product && !this.products.some((p) => p.id === res.product.id)) {
                    this.products.push({ ...res.product, _s: norm([res.product.name, res.product.brand, res.product.category].join(' ')) });
                }
                const row = { ...res.row, cells: Array.isArray(res.row.cells) ? {} : res.row.cells };
                let index = this.rows.findIndex((r) => r.id === row.id);
                if (index < 0) {
                    this.qty[row.id] = qtyText(row, row.quantity, this.units);
                    this.providers.forEach((p) => this.syncCell(row, p.id));
                    this.rows.push(row);
                    index = this.rows.length - 1;
                }
                this.search = '';
                this.busy = false;
                // Esperar a que Alpine dibuje la fila antes de enfocar la cantidad
                this.$nextTick(() => requestAnimationFrame(() => this.focusCell(index, 0)));
                return null;
            } catch (e) {
                if (!body.name) alert(e.message);
                return e.message;
            } finally {
                this.busy = false;
            }
        },
    };
}
