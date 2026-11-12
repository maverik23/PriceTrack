/**
 * Campo de texto con autocompletar sobre valores existentes (marca, categoría…).
 * Si lo escrito ya existe (sin importar mayúsculas/acentos) se usa el valor
 * existente; si no, se guarda tal cual como dato nuevo.
 *
 * Nota: no usa $refs/$root (ver AGENTS.md); todo se maneja con $event.
 */
const norm = (s) => (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

export default function combobox(options = [], value = '', noun = 'nueva') {
    return {
        all: [...new Set((options || []).filter(Boolean))],
        value: value ?? '',
        open: false,
        hi: 0,

        get match() {
            const v = norm(this.value);
            return v ? this.all.find((o) => norm(o) === v) : null;
        },
        get options() {
            const q = norm(this.value);
            const found = (q ? this.all.filter((o) => norm(o).includes(q)) : this.all)
                .sort((a, b) => (norm(a).startsWith(q) ? 0 : 1) - (norm(b).startsWith(q) ? 0 : 1))
                .slice(0, 8)
                .map((o) => ({ key: o, label: o, value: o }));
            const text = this.value.trim().replace(/\s+/g, ' ');
            if (text && !this.match) {
                found.push({ key: '__new', label: `Agregar «${text}» (${noun})`, value: text, isNew: true });
            }
            return found;
        },
        get hint() {
            const text = this.value.trim();
            if (!text) return '';
            return this.match ? (this.match !== text ? `Se usará «${this.match}»` : '') : `«${text}» se guardará como ${noun}`;
        },

        show() {
            this.open = true;
            this.hi = 0;
        },
        choose(opt) {
            this.value = opt.value;
            this.open = false;
        },
        /** Al salir, si coincide con uno existente, usar su escritura exacta. */
        settle() {
            setTimeout(() => {
                this.open = false;
                if (this.match) this.value = this.match;
            }, 120);
        },
        onKey(e) {
            const n = this.options.length;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!this.open) return this.show();
                this.hi = n ? (this.hi + 1) % n : 0;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.hi = n ? (this.hi - 1 + n) % n : 0;
            } else if (e.key === 'Enter' && this.open && n) {
                // Elegir la sugerencia en lugar de enviar el formulario
                e.preventDefault();
                this.choose(this.options[this.hi]);
            } else if (e.key === 'Tab' && this.open && n && this.value.trim() && !this.options[this.hi].isNew) {
                this.choose(this.options[this.hi]);
            } else if (e.key === 'Escape') {
                this.open = false;
            }
        },
    };
}
