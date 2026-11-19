/**
 * Renglones de ingredientes del formulario de receta. Cada renglón es un artículo
 * del súper (con cantidad en su misma dimensión: de ahí salen el costo y la lista
 * de compras) o texto libre ("Sal", "al gusto").
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
        /** Con artículo, solo las unidades de su dimensión (g y kg, ml y L, o pza). */
        unitsFor(row) {
            const product = this.product(row);
            const all = Object.keys(this.units);
            return product ? all.filter((u) => this.units[u].dimension === this.units[product.unit].dimension) : all;
        },
        onProduct(row) {
            const product = this.product(row);
            if (!product) return;
            row.name = '';
            if (!this.unitsFor(row).includes(row.unit)) row.unit = kitchenUnit[this.units[product.unit].dimension];
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
