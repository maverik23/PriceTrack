/**
 * Subida de fotos y documentos. Antes de enviar, las fotos grandes se reducen en el
 * navegador (el servidor no tiene GD) y de cada foto se hace una miniatura que va en
 * thumbs[i]. Si el navegador no puede leer la imagen (HEIC en Chrome), va tal cual.
 */
const MAX_SIDE = 2000;
const THUMB_SIDE = 480;
const KEEP_BELOW = 1.5 * 1024 * 1024;

async function shrink(file, side, quality, always = false) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif') return null;
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return null;
    }
    const scale = Math.min(1, side / Math.max(bitmap.width, bitmap.height));
    if (!always && scale === 1 && file.size < KEEP_BELOW) return null;

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    if (!blob) return null;
    const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';

    return new File([blob], name, { type: 'image/jpeg' });
}

function fileInput(name, files) {
    const input = document.createElement('input');
    input.type = 'file';
    input.name = name;
    input.hidden = true;
    const transfer = new DataTransfer();
    files.forEach((f) => transfer.items.add(f));
    input.files = transfer.files;
    return input;
}

export default function attachmentUpload() {
    return {
        busy: false,
        names: '',

        picked(event) {
            this.names = [...event.target.files].map((f) => f.name).join(', ');
        },

        async submit(event) {
            const form = event.target;
            const input = form.querySelector('input[type=file][data-files]');
            if (this.busy || !input.files.length || typeof DataTransfer === 'undefined') {
                if (!this.busy) form.submit();
                return;
            }
            this.busy = true;
            try {
                const files = [];
                let i = 0;
                for (const file of input.files) {
                    files.push((await shrink(file, MAX_SIDE, 0.85)) ?? file);
                    const thumb = await shrink(file, THUMB_SIDE, 0.8, true);
                    if (thumb) form.appendChild(fileInput(`thumbs[${i}]`, [thumb]));
                    i++;
                }
                input.removeAttribute('name');
                form.appendChild(fileInput('files[]', files));
            } catch {
                // Si algo falla, se suben los originales
            }
            form.submit();
        },
    };
}
