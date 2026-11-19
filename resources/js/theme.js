/**
 * Tema de la app: 'auto' (sigue al sistema), 'light' o 'dark'. Se guarda en este
 * navegador; el <head> lo aplica antes de pintar (layouts/app.blade.php).
 * Al cambiar avisa con el evento `theme-changed` (las gráficas se vuelven a dibujar).
 */
const system = window.matchMedia('(prefers-color-scheme: dark)');
const labels = { auto: 'Tema: automático', light: 'Tema: claro', dark: 'Tema: oscuro' };

function stored() {
    try {
        return localStorage.getItem('theme') || 'auto';
    } catch {
        return 'auto';
    }
}

function apply(choice) {
    const theme = choice === 'dark' || (choice === 'auto' && system.matches) ? 'dark' : 'light';
    if (document.documentElement.dataset.theme === theme) return;
    document.documentElement.dataset.theme = theme;
    window.dispatchEvent(new CustomEvent('theme-changed', { detail: theme }));
}

system.addEventListener('change', () => apply(stored()));

export default function themeToggle() {
    return {
        choice: stored(),
        get label() {
            return labels[this.choice];
        },
        init() {
            // Varios botones (barra lateral y barra del móvil) comparten la elección
            window.addEventListener('theme-choice', (e) => (this.choice = e.detail));
        },
        cycle() {
            const next = { auto: 'light', light: 'dark', dark: 'auto' }[this.choice];
            try {
                localStorage.setItem('theme', next);
            } catch {
                /* sin almacenamiento: dura lo que la página */
            }
            apply(next);
            window.dispatchEvent(new CustomEvent('theme-choice', { detail: next }));
        },
    };
}
