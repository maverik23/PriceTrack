import Alpine from 'alpinejs';

/**
 * Formulario de artículo: al escribir o escanear el código de barras busca sus
 * datos (GET products/lookup) y autocompleta nombre, marca, categoría y
 * presentación. Solo llena los campos vacíos; "Usar estos datos" los reemplaza
 * todos. Si el código ya es de otro artículo, lo avisa con su liga.
 *
 * Sin $refs/$root (ver AGENTS.md): los campos se buscan por id.
 */
export default function productForm(config) {
    return {
        barcode: config.barcode || '',
        loading: false,
        looked: null,
        found: null,
        existing: null,
        message: '',

        init() {
            if (config.autoLookup && this.barcode) this.lookup();
        },

        async lookup(force = false) {
            const code = this.barcode.replace(/[\s-]+/g, '');
            if (!code || (code === this.looked && !force) || this.loading) return;
            this.found = null;
            this.existing = null;
            this.message = '';
            if (!/^\d{6,14}$/.test(code)) {
                this.message = 'El código de barras debe tener de 6 a 14 dígitos.';
                return;
            }

            this.loading = true;
            this.looked = code;
            try {
                const res = await fetch(`${config.lookupUrl}?barcode=${encodeURIComponent(code)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message);
                // Se siguió escribiendo mientras buscaba: ignorar esta respuesta
                if (this.barcode.replace(/[\s-]+/g, '') !== code) return;

                this.barcode = data.barcode;
                this.looked = data.barcode;
                if (data.product && data.product.id !== config.productId) {
                    this.existing = data.product;
                } else if (data.suggestion) {
                    this.found = data.suggestion;
                    this.apply(false);
                } else if (!data.product) {
                    this.message = data.error || 'No encontramos este código en internet; captura los datos a mano.';
                }
            } catch (e) {
                this.message = e.message || 'No se pudo buscar el código.';
                this.looked = null;
            } finally {
                this.loading = false;
            }
        },

        /** Pasa los datos encontrados al formulario (force = también los campos ya llenos). */
        apply(force) {
            const s = this.found;
            if (!s) return;
            const field = (id) => document.getElementById(id);
            const empty = (el) => el && el.value.trim() === '';

            if (s.name && (force || empty(field('name')))) field('name').value = s.name;

            // Marca y categoría son combobox: se cambia su estado de Alpine
            for (const id of ['brand', 'category']) {
                const el = field(id);
                if (el && s[id] && (force || empty(el))) Alpine.$data(el).value = s[id];
            }

            // La presentación cuenta como vacía si sigue en el valor inicial (1 pza)
            const size = field('size');
            const unit = field('unit');
            const untouched = empty(size) || (Number(size.value) === 1 && unit.value === 'item');
            if (s.size && s.unit && (force || untouched)) {
                size.value = s.size;
                unit.value = s.unit;
            }
        },
    };
}
