/**
 * Pantalla de una receta: porciones en vivo, calificación con un toque y modo cocinar.
 *
 * El servidor (App\Support\RecipeView) manda la receta tal como está escrita (factor 1):
 * cantidades, costo y macros son lineales en el factor (porciones ÷ porciones que rinde),
 * así que aquí solo se multiplica. quantityLabel() replica RecipeIngredient::quantityLabel()
 * (con qty() de app/helpers.php e IngredientUnit::labelFor()): si cambias uno, cambia el otro.
 */

const money = (v) => '$' + Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** qty() de app/helpers.php: 3 decimales, coma de miles, sin ceros de sobra (1500 → "1,500", 0.25 → "0.25"). */
export function qty(value) {
    const fixed = (Math.round(value * 1000) / 1000).toFixed(3);
    const [int, dec] = fixed.split('.');
    const text = int.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + dec;
    return text.replace(/0+$/, '').replace(/\.$/, '');
}

/** "250 g", "1.5 tazas", "2 porciones"; vacío sin cantidad. */
export function quantityLabel(quantity, unit, factor, units) {
    if (!quantity) return '';
    const q = Math.round(quantity * factor * 1000) / 1000;
    const meta = unit ? units[unit] : null;
    const label = meta ? (Math.abs(q - 1) > 0.0001 ? meta.plural : meta.label) : '';
    return `${qty(q)} ${label}`.trim();
}

/** "8 min", "1 h 05 min". */
function clock(seconds) {
    seconds = Math.max(0, Math.ceil(seconds));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const pad = (n) => String(n).padStart(2, '0');
    return h ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

/** Minutos que menciona un paso sin minutos guardados ("hornea 25 minutos" → 25). */
function mentionedMinutes(text) {
    const m = (text || '').match(/(\d{1,3})\s*(?:a\s*\d{1,3}\s*)?(?:min\b|minutos?)/i);
    return m ? parseInt(m[1], 10) : null;
}

/** Tres pitidos con la bocina del navegador (si deja) y vibración. */
function alarm() {
    try {
        navigator.vibrate?.([400, 150, 400, 150, 600]);
    } catch {}
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        [0, 0.45, 0.9].forEach((at) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.0001, ctx.currentTime + at);
            gain.gain.exponentialRampToValueAtTime(0.4, ctx.currentTime + at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.35);
            osc.connect(gain).connect(ctx.destination);
            osc.start(ctx.currentTime + at);
            osc.stop(ctx.currentTime + at + 0.4);
        });
        setTimeout(() => ctx.close(), 2000);
    } catch {}
}

const storage = {
    get(key) {
        try {
            return JSON.parse(localStorage.getItem(key) || 'null');
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch {}
    },
};

export default function recipeView(config) {
    const steps = config.steps.map((s, i) => ({ ...s, index: i, suggested: s.minutes ? null : mentionedMinutes(s.body) }));

    return {
        ...config,
        steps,
        portions: config.initialServings,
        money,
        rating: config.rating,
        ratingBusy: false,
        ratingError: '',

        // Modo cocinar
        cooking: false,
        step: 0,
        panel: false,
        ticked: {},
        timers: {},
        now: Date.now(),
        tick: null,
        lock: null,
        touch: null,
        resume: null,

        init() {
            this.$watch('portions', () => this.syncUrl());
            const saved = storage.get(`cook:${this.id}`);
            // Retomar donde se quedó (si fue en las últimas 6 horas)
            if (saved && Date.now() - saved.at < 6 * 3600 * 1000 && saved.step > 0 && saved.step < this.steps.length) this.resume = saved.step;
        },

        // ---------- porciones ----------
        get servingsCount() {
            const n = Math.round(Number(this.portions));
            return n >= 1 && n <= 999 ? n : this.servings;
        },
        get factor() {
            return this.servingsCount / this.servings;
        },
        set(delta) {
            this.portions = Math.min(999, Math.max(1, this.servingsCount + delta));
        },
        /** Conserva ?servings= para que el enlace siga funcionando. */
        syncUrl() {
            const url = new URL(window.location.href);
            if (this.servingsCount === this.servings) url.searchParams.delete('servings');
            else url.searchParams.set('servings', this.servingsCount);
            window.history.replaceState(null, '', url);
        },
        portionsText(n = this.servingsCount) {
            return `${n} ${n === 1 ? 'porción' : 'porciones'}`;
        },
        amount(item) {
            return quantityLabel(item.quantity, item.unit, this.factor, this.units);
        },
        itemCost(item) {
            return item.cost === null ? null : item.cost * this.factor;
        },
        get total() {
            return config.cost === null ? null : config.cost * this.factor;
        },
        get perServing() {
            return config.cost === null ? null : config.cost / this.servings;
        },
        macro(key, perServing = true) {
            if (!this.nutrition) return null;
            const total = this.nutrition.total[key];
            return perServing ? total / this.servings : total * this.factor;
        },
        fmt(value, decimals = 0) {
            return value === null ? '—' : Number(value).toLocaleString('es-MX', { maximumFractionDigits: decimals });
        },
        shopUrl() {
            return `${this.urls.shop}?servings=${this.servingsCount}`;
        },

        // ---------- calificación ----------
        async rate(value) {
            if (this.ratingBusy) return;
            const next = this.rating === value ? 0 : value;
            const before = this.rating;
            this.rating = next || null;
            this.ratingBusy = true;
            this.ratingError = '';
            try {
                const res = await fetch(this.urls.rate, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ rating: next }),
                });
                if (!res.ok) throw new Error();
                this.rating = (await res.json()).rating;
            } catch {
                this.rating = before;
                this.ratingError = 'No se pudo guardar la calificación.';
            } finally {
                this.ratingBusy = false;
            }
        },

        // ---------- modo cocinar ----------
        async startCooking(from = 0) {
            this.step = from;
            this.cooking = true;
            this.resume = null;
            document.documentElement.style.overflow = 'hidden';
            this.tick = setInterval(() => this.onTick(), 250);
            try {
                await document.documentElement.requestFullscreen?.({ navigationUI: 'hide' });
            } catch {}
            this.keepAwake();
            this.save();
        },
        async stopCooking() {
            this.cooking = false;
            this.panel = false;
            document.documentElement.style.overflow = '';
            if (!Object.values(this.timers).some((t) => t.running)) {
                clearInterval(this.tick);
                this.tick = null;
            }
            try {
                await this.lock?.release();
            } catch {}
            this.lock = null;
            try {
                if (document.fullscreenElement) await document.exitFullscreen();
            } catch {}
        },
        /** La pantalla no se apaga mientras se cocina (si el navegador deja). */
        async keepAwake() {
            try {
                if ('wakeLock' in navigator && document.visibilityState === 'visible' && this.cooking) {
                    this.lock = await navigator.wakeLock.request('screen');
                }
            } catch {}
        },
        onVisible() {
            if (document.visibilityState === 'visible' && this.cooking) this.keepAwake();
            this.now = Date.now();
        },
        go(delta) {
            const next = this.step + delta;
            if (next < 0) return;
            if (next >= this.steps.length) return this.finish();
            this.step = next;
            this.save();
        },
        finish() {
            storage.set(`cook:${this.id}`, null);
            this.stopCooking();
        },
        save() {
            storage.set(`cook:${this.id}`, { step: this.step, at: Date.now() });
        },
        onKey(e) {
            if (!this.cooking || e.target.closest?.('input, textarea, select')) return;
            if (e.key === 'ArrowRight' || e.key === 'PageDown') {
                e.preventDefault();
                this.go(1);
            } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
                e.preventDefault();
                this.go(-1);
            } else if (e.key === 'Escape') {
                if (this.panel) this.panel = false;
                else this.stopCooking();
            } else if (e.key === 'i' || e.key === 'I') {
                this.panel = !this.panel;
            }
        },
        touchStart(e) {
            const t = e.changedTouches[0];
            this.touch = { x: t.clientX, y: t.clientY };
        },
        touchEnd(e) {
            if (!this.touch || this.panel) return;
            const t = e.changedTouches[0];
            const dx = t.clientX - this.touch.x;
            const dy = t.clientY - this.touch.y;
            this.touch = null;
            // Deslizar a lo ancho: izquierda = siguiente
            if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) this.go(dx < 0 ? 1 : -1);
        },
        get current() {
            return this.steps[this.step] ?? null;
        },
        get progress() {
            return this.steps.length ? ((this.step + 1) / this.steps.length) * 100 : 0;
        },

        // ---------- temporizadores (uno por paso; siguen corriendo al cambiar de paso) ----------
        timer(index) {
            return this.timers[index] ?? null;
        },
        startTimer(index, minutes) {
            const t = this.timers[index];
            if (t && !t.running && t.left > 0 && !t.done) {
                t.endAt = Date.now() + t.left * 1000;
                t.running = true;
            } else {
                this.timers[index] = { total: minutes * 60, left: minutes * 60, endAt: Date.now() + minutes * 60000, running: true, done: false };
            }
            if (!this.tick) this.tick = setInterval(() => this.onTick(), 250);
            // El audio se habilita con un toque del usuario: se prepara aquí
            try {
                (this._ctx ??= new (window.AudioContext || window.webkitAudioContext)()).resume();
            } catch {}
        },
        pauseTimer(index) {
            const t = this.timers[index];
            if (!t?.running) return;
            t.left = Math.max(0, (t.endAt - Date.now()) / 1000);
            t.running = false;
        },
        resetTimer(index) {
            delete this.timers[index];
        },
        addMinute(index) {
            const t = this.timers[index];
            if (!t) return;
            if (t.running) t.endAt += 60000;
            else t.left += 60;
            t.total += 60;
            t.done = false;
            if (!t.running) this.startTimer(index, 0);
        },
        left(index) {
            const t = this.timers[index];
            if (!t) return 0;
            return t.running ? Math.max(0, (t.endAt - this.now) / 1000) : t.left;
        },
        clock(index) {
            return clock(this.left(index));
        },
        onTick() {
            this.now = Date.now();
            for (const [index, t] of Object.entries(this.timers)) {
                if (t.running && t.endAt <= this.now) {
                    t.running = false;
                    t.left = 0;
                    t.done = true;
                    alarm();
                }
            }
        },
        get activeTimers() {
            return Object.entries(this.timers)
                .map(([index, t]) => ({ index: Number(index), ...t }))
                .filter((t) => t.running || t.done || t.left > 0);
        },
    };
}
