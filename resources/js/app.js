import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';
import 'chartjs-adapter-date-fns';
import listGrid from './list-grid';
import combobox from './combobox';

Alpine.data('listGrid', listGrid);
Alpine.data('combobox', combobox);
window.Alpine = Alpine;
Alpine.start();

const money = (v) => '$' + Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const palette = ['#059669', '#2563eb', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

/**
 * Gráficas declarativas: <canvas data-chart='{"type":"line","labels":[...],"datasets":[...],"format":"money"}'>
 */
function initCharts() {
    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        const config = JSON.parse(canvas.dataset.chart);
        const fmt = config.format === 'money' ? money : (v) => Number(v).toFixed(1);

        const datasets = config.datasets.map((ds, i) => ({
            borderColor: palette[i % palette.length],
            backgroundColor: config.type === 'bar' ? palette[i % palette.length] + 'cc' : palette[i % palette.length] + '22',
            borderWidth: 2,
            pointRadius: 3,
            tension: 0.25,
            ...ds,
        }));

        const scales = {
            y: {
                beginAtZero: config.beginAtZero ?? config.type === 'bar',
                ticks: { callback: (v) => fmt(v) },
                grid: { color: '#f1f5f9' },
            },
            x: { grid: { display: false } },
        };

        if (config.timeAxis) {
            scales.x = { type: 'time', time: { unit: 'month', tooltipFormat: 'dd/MM/yyyy' }, grid: { display: false } };
        }

        new Chart(canvas, {
            type: config.type || 'line',
            data: { labels: config.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'nearest', intersect: false },
                plugins: {
                    legend: { display: datasets.length > 1, position: 'bottom' },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label ?? ''}: ${fmt(ctx.parsed.y)}` } },
                },
                scales,
            },
        });
    });
}

/**
 * Al elegir un artículo en un formulario de precio, rellena tamaño y unidad
 * con la presentación habitual del artículo.
 */
function initProductPrefill() {
    document.querySelectorAll('select[data-prefill-product]').forEach((select) => {
        const form = select.closest('form');
        select.addEventListener('change', () => {
            const option = select.selectedOptions[0];
            if (!option || !option.dataset.size) return;
            form.querySelector('[name="size"]').value = option.dataset.size;
            form.querySelector('[name="unit"]').value = option.dataset.unit;
            updateUnitPreview(form);
        });
    });
}

/**
 * Vista previa en vivo del precio por unidad ($/L, $/kg, $/pza) mientras se captura.
 */
const toBase = { ml: 1, l: 1000, g: 1, kg: 1000, item: 1 };
const compare = { ml: [1000, 'L'], l: [1000, 'L'], g: [1000, 'kg'], kg: [1000, 'kg'], item: [1, 'pza'] };

function updateUnitPreview(form) {
    const out = form.querySelector('[data-unit-preview]');
    if (!out) return;
    const price = parseFloat(form.querySelector('[name="price"]')?.value);
    const size = parseFloat(form.querySelector('[name="size"]')?.value);
    const pack = parseInt(form.querySelector('[name="pack_qty"]')?.value || '1', 10);
    const unit = form.querySelector('[name="unit"]')?.value;
    if (!price || !size || !unit || !toBase[unit]) {
        out.textContent = '';
        return;
    }
    const perBase = price / (pack * size * toBase[unit]);
    const [factor, label] = compare[unit];
    out.textContent = `= ${money(perBase * factor)} /${label}`;
}

function initUnitPreview() {
    document.querySelectorAll('form').forEach((form) => {
        if (!form.querySelector('[data-unit-preview]')) return;
        form.addEventListener('input', () => updateUnitPreview(form));
        form.addEventListener('change', () => updateUnitPreview(form));
        updateUnitPreview(form);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initCharts();
    initProductPrefill();
    initUnitPreview();
});
