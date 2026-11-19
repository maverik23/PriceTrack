/**
 * Despensa: marcar y desmarcar rápido lo que hay en casa (pantalla Despensa y la
 * ficha del artículo). Cada cambio se guarda al momento (POST products/{id}/pantry).
 *
 * pantry: null = no se lleva en la despensa, 'stocked' = lo tengo, 'out' = se acabó.
 */
const norm = (s) => (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

async function save(row, body) {
    const res = await fetch(row.action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'No se pudo guardar');
    return data.row;
}

/** Un renglón: interruptor "lo tengo" y cuánto queda. */
function rowMethods() {
    return {
        busy: {},
        errors: {},
        saved: {},

        async toggle(row) {
            if (this.busy[row.id]) return;
            await this.send(row, { have: row.pantry !== 'stocked' });
        },
        async setQty(row, text) {
            if ((text || '') === (row.qty || '')) return;
            await this.send(row, { have: true, qty: text });
        },
        async forget(row) {
            await this.send(row, { have: false, forget: true });
        },
        async send(row, body) {
            this.busy[row.id] = true;
            delete this.errors[row.id];
            try {
                Object.assign(row, await save(row, body));
                this.saved[row.id] = true;
                setTimeout(() => delete this.saved[row.id], 1200);
            } catch (e) {
                this.errors[row.id] = e.message;
            } finally {
                delete this.busy[row.id];
            }
        },
    };
}

export function pantryList(config) {
    const rows = config.rows.map((r) => ({ ...r, _s: norm([r.name, r.brand, r.category].join(' ')) }));

    return {
        ...rowMethods(),
        rows,
        q: '',
        show: 'all',
        category: '',

        get filtered() {
            const q = norm(this.q);
            return this.rows.filter((r) => (!q || q.split(' ').every((w) => r._s.includes(w)))
                && (!this.category || (r.category || '') === this.category)
                && (this.show === 'all'
                    || (this.show === 'stocked' && r.pantry === 'stocked')
                    || (this.show === 'out' && r.pantry === 'out')));
        },
        /** Agrupado por categoría, en orden alfabético ("Sin categoría" al final). */
        get groups() {
            const groups = {};
            for (const r of this.filtered) (groups[r.category || ''] ??= []).push(r);
            return Object.keys(groups)
                .sort((a, b) => (a === '') - (b === '') || a.localeCompare(b, 'es'))
                .map((name) => ({ name: name || 'Sin categoría', rows: groups[name] }));
        },
        count(state) {
            return this.rows.filter((r) => r.pantry === state).length;
        },
    };
}

export function pantryToggle(row) {
    return { ...rowMethods(), row };
}
