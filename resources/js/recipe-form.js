/**
 * Formulario de receta: ingredientes agrupados por sección y pasos agrupados por etapa.
 *
 * Cada ingrediente es un artículo del súper (con cantidad que se pueda pasar a su unidad:
 * de ahí salen costo, macros y lista de compras), otra receta (sub-receta: en porciones,
 * o en g/ml si dice cuánto rinde) o texto libre ("Sal", "al gusto").
 *
 * Entre dimensiones se usan los datos del artículo, igual que Product::fromIngredient():
 * peso de una taza (volumen ↔ peso) y peso de una pieza (piezas ↔ peso/volumen).
 * Las sub-recetas aceptan las mismas unidades que RecipeData::checkSubrecipe().
 */
const blankRow = () => ({ product_id: '', subrecipe_id: '', name: '', quantity: '', unit: '', note: '' });
const blankStep = () => ({ body: '', minutes: '' });
// Unidad de cocina que se propone al elegir un artículo
const kitchenUnit = { mass: 'g', volume: 'ml', count: 'item' };

let key = 0;
const keyed = (obj) => ({ ...obj, key: ++key });

/** Agrupa renglones por un campo, en el orden en que aparece cada grupo. */
function group(items, field, make) {
    const groups = [];
    for (const item of items) {
        const name = item[field] || '';
        let g = groups.find((x) => x.name === name);
        if (!g) groups.push((g = keyed({ name, items: [] })));
        g.items.push(make(item));
    }
    return groups;
}

export default function recipeForm(config) {
    const sections = group(config.ingredients, 'section', (row) => {
        const r = keyed({ ...blankRow(), ...row });
        r.ref = r.product_id ? `p${r.product_id}` : r.subrecipe_id ? `r${r.subrecipe_id}` : '';
        return r;
    });
    const stages = group(config.steps, 'stage', (step) => keyed({ ...blankStep(), ...step }));

    return {
        products: config.products,
        recipes: config.recipes,
        units: config.units,
        sections: sections.length ? sections : [keyed({ name: '', items: [0, 1, 2].map(() => keyed({ ...blankRow(), ref: '' })) })],
        stages: stages.length ? stages : [keyed({ name: '', items: [keyed(blankStep())] })],

        // ---------- ingredientes ----------
        product(row) {
            return row.ref.startsWith('p') ? this.products.find((p) => `p${p.id}` === row.ref) : null;
        },
        recipe(row) {
            return row.ref.startsWith('r') ? this.recipes.find((r) => `r${r.id}` === row.ref) : null;
        },
        /** ¿Se puede pasar esta unidad a la del artículo? */
        convertible(unit, product) {
            const from = this.units[unit].dimension;
            const to = product.dimension;
            if (from === 'serving') return false;
            if (from === to) return true;
            if ((from === 'volume' && to === 'mass') || (from === 'mass' && to === 'volume')) return !!product.cup;
            if (from === 'count' || (to === 'count' && from === 'mass')) return !!product.piece;
            if (to === 'count' && from === 'volume') return !!(product.piece && product.cup);
            return false;
        },
        /** Con artículo, solo las unidades que se pueden convertir a la suya; con receta, porciones o lo que rinde. */
        unitsFor(row) {
            const all = Object.keys(this.units);
            const product = this.product(row);
            if (product) return all.filter((u) => this.convertible(u, product));
            const recipe = this.recipe(row);
            if (recipe) return all.filter((u) => u === 'serving' || this.units[u].dimension === recipe.yield);
            return all;
        },
        onRef(row) {
            row.product_id = row.ref.startsWith('p') ? row.ref.slice(1) : '';
            row.subrecipe_id = row.ref.startsWith('r') ? row.ref.slice(1) : '';
            const product = this.product(row);
            const recipe = this.recipe(row);
            if (product || recipe) row.name = '';
            if (!this.unitsFor(row).includes(row.unit)) {
                row.unit = product ? kitchenUnit[product.dimension] : recipe ? 'serving' : row.unit;
            }
        },
        hint(row) {
            const recipe = this.recipe(row);
            if (!recipe) return '';
            return `Sub-receta: rinde ${recipe.servings} porc.` + (recipe.yieldLabel ? ` (${recipe.yieldLabel})` : ' · agrégale cuánto rinde para usarla en g o ml');
        },
        /** Índice del renglón en el arreglo que se envía (las secciones van en orden). */
        index(si, ri) {
            return this.sections.slice(0, si).reduce((n, s) => n + s.items.length, 0) + ri;
        },
        addRow(section) {
            section.items.push(keyed({ ...blankRow(), ref: '' }));
        },
        removeRow(section, ri) {
            section.items.splice(ri, 1);
            if (!section.items.length && this.sections.length > 1) this.sections.splice(this.sections.indexOf(section), 1);
            else if (!section.items.length) this.addRow(section);
        },
        moveRow(section, ri, delta) {
            const to = ri + delta;
            if (to < 0 || to >= section.items.length) return;
            const [row] = section.items.splice(ri, 1);
            section.items.splice(to, 0, row);
        },
        addSection() {
            this.sections.push(keyed({ name: '', items: [keyed({ ...blankRow(), ref: '' })] }));
            this.$nextTick(() => document.getElementById(`section-${this.sections.at(-1).key}`)?.focus());
        },

        // ---------- pasos ----------
        stepIndex(gi, si) {
            return this.stages.slice(0, gi).reduce((n, g) => n + g.items.length, 0) + si;
        },
        stepNumber(gi, si) {
            return this.stepIndex(gi, si) + 1;
        },
        addStep(stage) {
            stage.items.push(keyed(blankStep()));
            this.$nextTick(() => document.getElementById(`step-${stage.items.at(-1).key}`)?.focus());
        },
        removeStep(stage, si) {
            stage.items.splice(si, 1);
            if (!stage.items.length && this.stages.length > 1) this.stages.splice(this.stages.indexOf(stage), 1);
            else if (!stage.items.length) this.addStep(stage);
        },
        moveStep(stage, si, delta) {
            const to = si + delta;
            if (to < 0 || to >= stage.items.length) return;
            const [step] = stage.items.splice(si, 1);
            stage.items.splice(to, 0, step);
        },
        addStage() {
            this.stages.push(keyed({ name: '', items: [keyed(blankStep())] }));
            this.$nextTick(() => document.getElementById(`stage-${this.stages.at(-1).key}`)?.focus());
        },
        /** Pegar varios renglones en un paso vacío los reparte en un paso por renglón. */
        pasteSteps(event, stage, si) {
            const text = event.clipboardData?.getData('text') ?? '';
            const step = stage.items[si];
            const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
            if (lines.length < 2 || step.body.trim()) return;
            event.preventDefault();
            step.body = lines[0];
            stage.items.splice(si + 1, 0, ...lines.slice(1).map((body) => keyed({ ...blankStep(), body })));
        },
    };
}

/**
 * Etiquetas: chips con autocompletar sobre las existentes (reusa su escritura,
 * "mealprep" → "Mealprep") y las nuevas se crean al guardar.
 */
const norm = (s) => (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

export function tagInput(options = [], value = []) {
    return {
        all: options,
        tags: [...value],
        text: '',
        open: false,
        hi: 0,

        get suggestions() {
            const q = norm(this.text);
            const taken = this.tags.map(norm);
            const found = this.all.filter((t) => !taken.includes(norm(t)) && (!q || norm(t).includes(q))).slice(0, 8);
            const typed = this.text.trim();
            if (typed && !this.all.some((t) => norm(t) === q) && !taken.includes(q)) found.push({ isNew: true, label: typed });
            return found.map((t) => (typeof t === 'string' ? { label: t } : t));
        },
        add(label) {
            label = (label || '').trim().replace(/\s+/g, ' ');
            if (!label) return;
            const existing = this.all.find((t) => norm(t) === norm(label));
            if (!this.tags.some((t) => norm(t) === norm(label))) this.tags.push(existing ?? label);
            this.text = '';
            this.hi = 0;
        },
        remove(i) {
            this.tags.splice(i, 1);
        },
        onKey(e) {
            const n = this.suggestions.length;
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                if (this.open && n && this.text.trim()) this.add(this.suggestions[this.hi].label);
                else this.add(this.text);
            } else if (e.key === 'Backspace' && !this.text && this.tags.length) {
                this.tags.pop();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.open = true;
                this.hi = n ? (this.hi + 1) % n : 0;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.hi = n ? (this.hi - 1 + n) % n : 0;
            } else if (e.key === 'Escape') {
                this.open = false;
            }
        },
        /** Al salir, lo escrito se vuelve etiqueta (no se pierde). */
        settle() {
            setTimeout(() => {
                this.open = false;
                if (this.text.trim()) this.add(this.text);
            }, 150);
        },
    };
}
