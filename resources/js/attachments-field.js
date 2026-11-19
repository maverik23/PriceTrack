/**
 * Fotos y documentos dentro del formulario de alta y edición (recetas, bienes): subir,
 * ordenar, elegir la portada (la primera foto), quitar y poner título. Lo guarda
 * App\Support\AttachmentForm junto con el resto del formulario.
 *
 * El servidor de producción no tiene GD: antes de enviar, las fotos grandes se reducen
 * aquí a 2000 px y de cada foto se hace una miniatura de 480 px (thumbs[i]). Si el
 * navegador no puede leer la imagen (HEIC en Chrome), se sube la original.
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
    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
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

function hidden(name, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    input.dataset.attachmentsField = '';
    return input;
}

let seq = 0;

export default function attachmentsField(config) {
    return {
        items: config.existing.map((a) => ({ ...a, isNew: false })),
        removed: [],
        maxFiles: config.maxFiles,
        maxBytes: config.maxKb * 1024,
        error: '',
        preparing: false,
        dragging: false,

        init() {
            const form = this.$el.closest('form');
            form.addEventListener('submit', (event) => this.submit(event, form));
        },
        get photos() {
            return this.items.filter((i) => i.photo);
        },
        get documents() {
            return this.items.filter((i) => !i.photo);
        },
        get coverKey() {
            return this.photos[0]?.key ?? null;
        },
        get newCount() {
            return this.items.filter((i) => i.isNew).length;
        },
        pick(event) {
            this.add([...(event.target.files || event.dataTransfer?.files || [])]);
            if (event.target.value !== undefined) event.target.value = '';
        },
        add(files) {
            this.error = '';
            for (const file of files) {
                if (this.newCount >= this.maxFiles) {
                    this.error = `Sube hasta ${this.maxFiles} archivos a la vez; guarda y agrega el resto después.`;
                    break;
                }
                // Las fotos se reducen antes de subir: solo los documentos tienen que caber tal cual
                if (!file.type.startsWith('image/') && file.size > this.maxBytes) {
                    this.error = `«${file.name}» pesa más de ${Math.round(this.maxBytes / 1048576)} MB.`;
                    continue;
                }
                const photo = file.type.startsWith('image/') || /\.(heic|heif)$/i.test(file.name);
                this.items.push({
                    key: `new${++seq}`,
                    isNew: true,
                    file,
                    photo,
                    viewable: photo && !/heic|heif/i.test(file.type + file.name),
                    thumb: photo ? URL.createObjectURL(file) : null,
                    name: file.name,
                    title: '',
                    size: file.size >= 1048576 ? `${(file.size / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(file.size / 1024))} KB`,
                });
            }
        },
        remove(item) {
            this.items.splice(this.items.indexOf(item), 1);
            if (item.isNew) URL.revokeObjectURL(item.thumb);
            else this.removed.push(item);
        },
        restore(item) {
            this.removed.splice(this.removed.indexOf(item), 1);
            this.items.push(item);
        },
        /** Mueve entre fotos (o entre documentos), sin mezclar. */
        move(item, delta) {
            const group = item.photo ? this.photos : this.documents;
            const target = group[group.indexOf(item) + delta];
            if (!target) return;
            const a = this.items.indexOf(item);
            const b = this.items.indexOf(target);
            [this.items[a], this.items[b]] = [this.items[b], this.items[a]];
        },
        makeCover(item) {
            this.items.splice(this.items.indexOf(item), 1);
            this.items.unshift(item);
        },

        /** Al guardar: reduce las fotos nuevas, arma los campos y envía. */
        async submit(event, form) {
            form.querySelectorAll('[data-attachments-field]').forEach((el) => el.remove());
            for (const item of this.removed) form.appendChild(hidden('remove_attachments[]', item.id));
            for (const item of this.items) {
                if (!item.isNew) form.appendChild(hidden(`attachment_titles[${item.id}]`, item.title || ''));
            }
            const fresh = this.items.filter((i) => i.isNew);
            const order = this.items.map((i) => (i.isNew ? `n${fresh.indexOf(i)}` : i.key));
            for (const token of order) form.appendChild(hidden('attachment_order[]', token));
            if (!fresh.length || typeof DataTransfer === 'undefined' || event.defaultPrevented) return;

            event.preventDefault();
            if (this.preparing) return;
            this.preparing = true;
            const files = [];
            try {
                for (const [i, item] of fresh.entries()) {
                    files.push((await shrink(item.file, MAX_SIDE, 0.85)) ?? item.file);
                    const thumb = await shrink(item.file, THUMB_SIDE, 0.8, true);
                    if (thumb) {
                        const input = fileInput(`thumbs[${i}]`, [thumb]);
                        input.dataset.attachmentsField = '';
                        form.appendChild(input);
                    }
                }
            } catch {
                // Si algo falla se suben los originales
                files.splice(0, files.length, ...fresh.map((i) => i.file));
            }
            const input = fileInput('files[]', files);
            input.dataset.attachmentsField = '';
            form.appendChild(input);
            form.querySelectorAll('button:not([type=button])').forEach((b) => b.classList.add('is-busy'));
            HTMLFormElement.prototype.submit.call(form);
        },
    };
}
