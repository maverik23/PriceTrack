/**
 * Renglones de ingredientes del formulario de receta. Cada renglón es un artículo
 * del súper (con cantidad que se pueda pasar a su unidad: de ahí salen costo, macros y
 * lista de compras) o texto libre ("Sal", "al gusto").
 *
 * Entre dimensiones se usan los datos del artículo, igual que Product::fromIngredient():
 * peso de una taza (volumen ↔ peso) y peso de una pieza (piezas ↔ peso/volumen).
 */
const blank = () => ({ product_id: '', name: '', quantity: '', unit: '', note: '' });
// Unidad de cocina que se propone al elegir un artículo
const kitchenUnit = { mass: 'g', volume: 'ml', count: 'item' };

export default function recipeForm(config) {
    let key = 0;
    const keyed = (row) => ({ ...blank(), ...row, key: ++key });

    return {
        products: config.products,
        units: config.units,
        rows: (config.ingredients.length ? config.ingredients : [blank(), blank(), blank()]).map(keyed),

        product(row) {
            return this.products.find((p) => String(p.id) === String(row.product_id));
        },
        /** ¿Se puede pasar esta unidad a la del artículo? */
        convertible(unit, product) {
            const from = this.units[unit].dimension;
            const to = product.dimension;
            if (from === to) return true;
            if ((from === 'volume' && to === 'mass') || (from === 'mass' && to === 'volume')) return !!product.cup;
            if (from === 'count' || (to === 'count' && from === 'mass')) return !!product.piece;
            if (to === 'count' && from === 'volume') return !!(product.piece && product.cup);
            return false;
        },
        /** Con artículo, solo las unidades que se pueden convertir a la suya. */
        unitsFor(row) {
            const product = this.product(row);
            const all = Object.keys(this.units);
            return product ? all.filter((u) => this.convertible(u, product)) : all;
        },
        onProduct(row) {
            const product = this.product(row);
            if (!product) return;
            row.name = '';
            if (!this.unitsFor(row).includes(row.unit)) row.unit = kitchenUnit[product.dimension];
        },
        add() {
            this.rows.push(keyed({}));
        },
        remove(index) {
            this.rows.splice(index, 1);
            if (!this.rows.length) this.add();
        },
    };
}
