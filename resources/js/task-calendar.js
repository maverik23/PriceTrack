/**
 * Tareas: arrastrar una tarea pendiente (del calendario, del día elegido o del panel de
 * vencidas) y soltarla en un día del calendario cambia su fecha (PATCH tasks/{id}/move).
 *
 * - Lo que se arrastra lleva `data-task-drag='{"id","url","title","due"}'`; los días, `data-drop-day="AAAA-MM-DD"`.
 * - Todo va por delegación en `document`, así sigue funcionando después de que el filtro en
 *   vivo reemplaza la zona de resultados.
 * - Al soltar se recarga la zona con `?day=` (el día queda elegido) y se avisa con "Deshacer".
 * - Arrastrar con el ratón; en pantallas táctiles la fecha se cambia en la tarea.
 */
import { loadFiltered } from './live-filter';

const TYPE = 'application/x-pricetrack-task';
let dragging = null;
let hideTimer = null;

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function clearTargets() {
    document.querySelectorAll('[data-drop-day].is-drop').forEach((el) => el.classList.remove('is-drop'));
}

function status(message, undo = null) {
    const box = document.getElementById('task-move-status');
    if (!box) return;
    clearTimeout(hideTimer);
    box.replaceChildren();
    const text = document.createElement('span');
    text.textContent = message;
    box.append(text);
    if (undo) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'link ml-2';
        button.textContent = 'Deshacer';
        button.addEventListener('click', () => {
            box.hidden = true;
            undo();
        });
        box.append(button);
    }
    box.hidden = false;
    hideTimer = setTimeout(() => (box.hidden = true), 8000);
}

async function move(task, day, { undoable = true } = {}) {
    const res = await fetch(task.url, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ due_on: day }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        status(data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : 'No se pudo mover la tarea.'));
        return;
    }

    const url = new URL(window.location.href);
    // El mismo mes (el día soltado siempre está en la cuadrícula), con ese día elegido
    url.searchParams.set('day', data.due_on);
    await loadFiltered(url.toString());

    const moved = { ...task, due: data.due_on };
    status(`«${task.title}» pasó al ${data.label}.`, undoable && data.from ? () => move(moved, data.from, { undoable: false }) : null);
}

export function initTaskDrag() {
    document.addEventListener('dragstart', (e) => {
        const el = e.target.closest?.('[data-task-drag]');
        if (!el) return;
        try {
            dragging = JSON.parse(el.dataset.taskDrag);
        } catch {
            return;
        }
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData(TYPE, String(dragging.id));
        e.dataTransfer.setData('text/plain', dragging.title);
        document.documentElement.classList.add('is-dragging-task');
    });

    document.addEventListener('dragend', () => {
        dragging = null;
        clearTargets();
        document.documentElement.classList.remove('is-dragging-task');
    });

    document.addEventListener('dragover', (e) => {
        const cell = e.target.closest?.('[data-drop-day]');
        if (!cell || !dragging) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (!cell.classList.contains('is-drop')) {
            clearTargets();
            cell.classList.add('is-drop');
        }
    });

    document.addEventListener('dragleave', (e) => {
        const cell = e.target.closest?.('[data-drop-day]');
        if (cell && !cell.contains(e.relatedTarget)) cell.classList.remove('is-drop');
    });

    document.addEventListener('drop', (e) => {
        const cell = e.target.closest?.('[data-drop-day]');
        if (!cell || !dragging) return;
        e.preventDefault();
        const task = dragging;
        const day = cell.dataset.dropDay;
        dragging = null;
        clearTargets();
        if (day !== task.due) move(task, day);
    });
}
