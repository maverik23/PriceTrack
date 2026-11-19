/**
 * Despensa: marcar y desmarcar rápido lo que hay en casa (pantalla Despensa y la
 * ficha del artículo). Cada cambio se guarda al momento (POST products/{id}/pantry).
 *
 * pantry: null = no se lleva en la despensa, 'stocked' = lo tengo, 'out' = se acabó.
 * Al acabarse algo se pregunta si se agrega a una lista de compras (POST products/{id}/pantry/list).
 * `value` = lo que vale lo que queda (estimado si es "suficiente").
 */
const norm = (s) => (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

const PER_PAGE = [10, 20, 50, 100];

function savedPerPage() {
    try {
        const saved = Number(localStorage.getItem('pantry:perPage'));
        return PER_PAGE.includes(saved) ? saved : 20;
    } catch {
        return 20;
    }
}

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
        // Paginación en el navegador (como Paginate::OPTIONS); cuántos por página se recuerda
        perPageOptions: PER_PAGE,
        perPage: savedPerPage(),
        page: 1,

        init() {
            this.$watch('q', () => (this.page = 1));
            this.$watch('show', () => (this.page = 1));
            this.$watch('category', () => (this.page = 1));
            this.$watch('perPage', (value) => {
                this.page = 1;
                try {
                    localStorage.setItem('pantry:perPage', String(value));
                } catch {
                    // Sin almacenamiento: solo dura en esta visita
                }
            });
        },

        get filtered() {
            const q = norm(this.q);
            return this.rows.filter((r) => (!q || q.split(' ').every((w) => r._s.includes(w)))
                && (!this.category || (r.category || '') === this.category)
                && (this.show === 'all'
                    || (this.show === 'stocked' && r.pantry === 'stocked')
                    || (this.show === 'out' && r.pantry === 'out')));
        },
        /** Lo filtrado en el orden en que se muestra: por categoría ("Sin categoría" al final) y nombre. */
        get sorted() {
            const cat = (r) => r.category || '';
            return [...this.filtered].sort((a, b) => (cat(a) === '') - (cat(b) === '')
                || cat(a).localeCompare(cat(b), 'es')
                || a.name.localeCompare(b.name, 'es'));
        },
        get pages() {
            return Math.max(1, Math.ceil(this.filtered.length / this.perPage));
        },
        get currentPage() {
            return Math.min(this.page, this.pages);
        },
        get pageRows() {
            const start = (this.currentPage - 1) * this.perPage;
            return this.sorted.slice(start, start + this.perPage);
        },
        goTo(page) {
            this.page = Math.min(Math.max(1, page), this.pages);
            document.getElementById('pantry-groups')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        /** La página agrupada por categoría. */
        get groups() {
            const groups = [];
            for (const r of this.pageRows) {
                const name = r.category || 'Sin categoría';
                if (groups.at(-1)?.name !== name) groups.push({ name, rows: [] });
                groups.at(-1).rows.push(r);
            }
            return groups;
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
