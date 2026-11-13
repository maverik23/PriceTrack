/**
 * Escáner de códigos de barras con la cámara (ventana única, en el layout).
 *
 * Se abre con un evento: $dispatch('scan-barcode', { input: 'id-del-input' })
 * (lo hace <x-scan-button for="…">). Al leer un código lo escribe en ese input,
 * dispara `input` (para x-model) y luego `barcode-scanned` con { code }, que
 * cada pantalla escucha para buscar o agregar el artículo.
 *
 * Usa el BarcodeDetector del navegador si lo tiene (Chrome en Android) y si no
 * zxing en WebAssembly (barcode-detector), que se descarga solo al usarlo y se
 * sirve desde la propia app, sin CDN. La cámara en vivo exige HTTPS (o
 * localhost); sin eso queda la opción de tomar una foto del código.
 */
const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128'];

let detectorPromise = null;

function getDetector() {
    detectorPromise ??= (async () => {
        if ('BarcodeDetector' in window) {
            try {
                const supported = await window.BarcodeDetector.getSupportedFormats();
                const formats = FORMATS.filter((f) => supported.includes(f));
                if (formats.includes('ean_13')) return new window.BarcodeDetector({ formats });
            } catch {
                // Sin soporte real: usar zxing
            }
        }
        const [{ BarcodeDetector, prepareZXingModule }, { default: wasmUrl }] = await Promise.all([
            import('barcode-detector/ponyfill'),
            import('zxing-wasm/reader/zxing_reader.wasm?url'),
        ]);
        prepareZXingModule({
            overrides: { locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : prefix + path) },
        });
        return new BarcodeDetector({ formats: FORMATS });
    })();
    // Si falló la descarga, reintentar la próxima vez
    detectorPromise.catch(() => (detectorPromise = null));
    return detectorPromise;
}

export default function barcodeScanner() {
    return {
        open: false,
        live: false,
        status: '',
        error: '',
        input: null,
        stream: null,
        timer: null,

        async start(detail) {
            this.stop();
            this.input = detail?.input;
            this.open = true;
            this.error = '';
            this.live = Boolean(window.isSecureContext && navigator.mediaDevices?.getUserMedia);
            const detector = getDetector();
            detector.catch(() => (this.error = 'No se pudo cargar el lector de códigos.'));

            if (!this.live) {
                this.status = 'Toma una foto del código de barras, de cerca y bien enfocado.';
                return;
            }

            this.status = 'Abriendo la cámara…';
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: false,
                });
                // Se cerró mientras pedía permiso
                if (!this.open) return stream.getTracks().forEach((t) => t.stop());
                this.stream = stream;
                const video = document.getElementById('scanner-video');
                video.srcObject = stream;
                await video.play();
                this.status = 'Apunta al código de barras.';
                this.scan(video, await detector);
            } catch (e) {
                this.live = false;
                this.status = 'Toma una foto del código de barras.';
                this.error = e?.name === 'NotAllowedError' ? 'No se dio permiso para usar la cámara.' : 'No se pudo abrir la cámara.';
            }
        },

        scan(video, detector) {
            const tick = async () => {
                if (!this.open || !this.stream) return;
                if (video.readyState >= 2) {
                    try {
                        const code = (await detector.detect(video)).find((c) => c.rawValue)?.rawValue;
                        if (code) return this.done(code);
                    } catch {
                        // Cuadro ilegible: seguir intentando
                    }
                }
                this.timer = setTimeout(tick, 120);
            };
            tick();
        },

        async fromPhoto(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file) return;
            this.error = '';
            this.status = 'Leyendo la foto…';
            try {
                const detector = await getDetector();
                const image = await createImageBitmap(file);
                const code = (await detector.detect(image)).find((c) => c.rawValue)?.rawValue;
                if (code) return this.done(code);
                this.error = 'No se encontró un código en la foto. Intenta más de cerca y con buena luz.';
            } catch {
                this.error = 'No se pudo leer la foto.';
            }
            this.status = '';
        },

        done(code) {
            const input = this.input && document.getElementById(this.input);
            this.close();
            if (!input) return;
            input.value = code;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new CustomEvent('barcode-scanned', { detail: { code }, bubbles: true }));
        },

        stop() {
            clearTimeout(this.timer);
            this.stream?.getTracks().forEach((t) => t.stop());
            this.stream = null;
            const video = document.getElementById('scanner-video');
            if (video) video.srcObject = null;
        },

        close() {
            this.stop();
            this.open = false;
        },
    };
}
