/**
 * Formulario de proveedor (providers/_form): cadena y sucursal.
 *
 * - Al escribir el nombre de un proveedor nuevo se adivina la cadena (mismos patrones que
 *   StoreBranches::guess(), los manda la vista), mientras no se haya elegido a mano.
 * - Las cadenas que listan sus sucursales (Chedraui, Costco) las piden al servidor con el código
 *   postal (GET settings/branches) y se elige de la lista; las demás se escriben (número y nombre).
 */
import { requestJson } from './online-prices';

export default function providerForm(config) {
    return {
        chains: config.chains,
        postal: config.postalCode || '',
        chain: config.chain || '',
        branch: config.branch || '',
        branchName: config.branchName || '',
        touched: !!config.chain || !config.isNew,
        branches: [],
        loading: false,
        error: '',

        init() {
            this.load();
            this.$watch('chain', () => {
                this.branch = '';
                this.branchName = '';
                this.load();
            });
        },
        get info() {
            return this.chains[this.chain] || null;
        },
        get listable() {
            return !!this.info?.list;
        },
        guess(name) {
            if (this.touched) return;
            const found = Object.entries(this.chains).find(([, c]) => new RegExp(c.match, 'iu').test(name || ''));
            const key = found ? found[0] : '';
            if (key !== this.chain) this.chain = key;
        },
        pickChain() {
            this.touched = true;
        },
        async load() {
            this.branches = [];
            this.error = '';
            if (!this.listable || !/^\d{5}$/.test(this.postal)) return;
            this.loading = true;
            try {
                const params = new URLSearchParams({ chain: this.chain, postal_code: this.postal });
                if (config.providerId) params.set('provider_id', config.providerId);
                const data = await requestJson('GET', `${config.branchesUrl}?${params}`, null, { retry: false });
                this.branches = data.branches;
                // La guardada puede ya no estar entre las cercanas: se conserva como opción
                if (this.branch && this.branch !== 'auto' && !this.branches.some((b) => b.id === this.branch)) {
                    this.branches.push({ id: this.branch, label: this.branchName || this.branch });
                }
                if (!this.branches.length) this.error = `No encontré sucursales de ${this.info.name} cerca de ${this.postal}.`;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },
        choose() {
            this.branchName = this.branches.find((b) => b.id === this.branch)?.label || '';
        },
    };
}
