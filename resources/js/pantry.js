/**
 * Despensa: marcar y desmarcar rápido lo que hay en casa (pantalla Despensa y la
 * ficha del artículo). Cada cambio se guarda al momento (POST products/{id}/pantry).
 *
 * pantry: null = no se lleva en la despensa, 'stocked' = lo tengo, 'out' = se acabó.
 * Al acabarse algo se pregunta si se agrega a una lista de compras (POST products/{id}/pantry/list).
 * `value` = lo que vale lo que queda (estimado si es "suficiente").
 */
const norm = (s) => (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

const money = (v) => '$' + Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function save(row, body, url = row.action) {
    const res = await fetch(url, {
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
    return data;
}

/** Un renglón: interruptor "lo tengo", cuánto queda y, si se acabó, mandarlo a una lista. */
function rowMethods(lists = []) {
    return {
        busy: {},
        errors: {},
        saved: {},
        // Renglones donde se pregunta "¿agregar a la lista?" y lo que se respondió
        asking: {},
        added: {},
        lists,
        listChoice: lists[0]?.id ?? '',
        money,

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
            const before = row.pantry;
            try {
                Object.assign(row, (await save(row, body)).row);
                // Se acabó: ¿a la lista? (si no está ya pendiente en alguna)
                if (before === 'stocked' && row.pantry === 'out' && !row.in_list) this.asking[row.id] = true;
                if (row.pantry !== 'out') delete this.asking[row.id];
                this.saved[row.id] = true;
                setTimeout(() => delete this.saved[row.id], 1200);
            } catch (e) {
                this.errors[row.id] = e.message;
            } finally {
                delete this.busy[row.id];
            }
        },
        ask(row) {
            this.asking[row.id] = true;
        },
        dismiss(row) {
            delete this.asking[row.id];
        },
        /** Lo agrega a la lista elegida ('' = una lista nueva). */
        async addToList(row) {
            this.busy[row.id] = true;
            delete this.errors[row.id];
            try {
                const data = await save(row, { list_id: this.listChoice || null }, row.to_list);
                Object.assign(row, data.row);
                if (!this.lists.some((l) => l.id === data.list.id)) {
                    this.lists.unshift({ id: data.list.id, name: data.list.name });
                    this.listChoice = data.list.id;
                }
                this.added[row.id] = data.list;
                delete this.asking[row.id];
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
        ...rowMethods(config.lists || []),
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
        /** Lo que vale lo que hay en casa (con lo filtrado) y si algo es estimado. */
        get value() {
            const stocked = this.filtered.filter((r) => r.pantry === 'stocked' && r.value !== null);
            return {
                total: stocked.reduce((sum, r) => sum + r.value, 0),
                estimated: stocked.some((r) => r.estimated),
                priced: stocked.length,
                unpriced: this.filtered.filter((r) => r.pantry === 'stocked' && r.value === null).length,
            };
        },
    };
}

export function pantryToggle(row, lists = []) {
    return { ...rowMethods(lists), row };
}
