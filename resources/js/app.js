import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';
import 'chartjs-adapter-date-fns';
import listGrid from './list-grid';
import combobox from './combobox';
import barcodeScanner from './barcode-scanner';
import productForm from './product-form';
import recipeForm, { tagInput } from './recipe-form';
import recipeView from './recipe-show';
import attachmentUpload from './attachment-upload';
import themeToggle from './theme';
import { pantryList, pantryToggle } from './pantry';
import { branchPicker } from './online-prices';
import productPrices, { priceLink, takeFlash } from './product-prices';

Alpine.data('listGrid', listGrid);
Alpine.data('combobox', combobox);
Alpine.data('barcodeScanner', barcodeScanner);
Alpine.data('productForm', productForm);
Alpine.data('recipeForm', recipeForm);
Alpine.data('tagInput', tagInput);
Alpine.data('recipeView', recipeView);
Alpine.data('attachmentUpload', attachmentUpload);
Alpine.data('themeToggle', themeToggle);
Alpine.data('pantryList', pantryList);
Alpine.data('pantryToggle', pantryToggle);
Alpine.data('branchPicker', branchPicker);
Alpine.data('productPrices', productPrices);
Alpine.data('priceLink', priceLink);
window.takeFlash = takeFlash;
window.Alpine = Alpine;
Alpine.start();

const money = (v) => '$' + Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Colores de las series, por tema: jade, talavera, cempasúchil, chile, ciruela, turquesa, rosa, olivo
const palettes = {
    light: ['#1c7a5c', '#3b6fd4', '#e0932a', '#d9513c', '#9b4f96', '#2a9d9f', '#d6578c', '#7c9a2e'],
    dark: ['#4cc79a', '#7ba2f0', '#f0b354', '#f0806c', '#c98ac6', '#5cc8ca', '#ee8fb5', '#a9c75a'],
};
// Una serie puede pedir un color por nombre desde la vista: ['color' => 'chile']
const named = { jade: 0, talavera: 1, sol: 2, chile: 3, ciruela: 4 };
const chrome = {
    light: { text: '#5f6b62', grid: '#ebe7dc', tooltip: '#1f2a25' },
    dark: { text: '#a3ada5', grid: '#33403a', tooltip: '#0f1613' },
};

Chart.defaults.font.family = "'Figtree Variable', ui-sans-serif, system-ui, sans-serif";
Chart.defaults.font.size = 12;

const charts = [];

/**
 * Gráficas declarativas: <canvas data-chart='{"type":"line","labels":[...],"datasets":[...],"format":"money"}'>
 * Se vuelven a dibujar al cambiar de tema (evento `theme-changed`).
 */
function initCharts() {
    charts.splice(0).forEach((chart) => chart.destroy());
    const theme = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
    const palette = palettes[theme];
    const ui = chrome[theme];
    Chart.defaults.color = ui.text;

    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        const config = JSON.parse(canvas.dataset.chart);
        const fmt = config.format === 'money' ? money : (v) => Number(v).toFixed(1);
        const bar = config.type === 'bar';

        const datasets = config.datasets.map(({ color, ...ds }, i) => {
            const base = palette[named[color] ?? i % palette.length];
            return {
                borderColor: base,
                backgroundColor: bar ? base + 'd9' : base + '1f',
                pointBackgroundColor: base,
                borderWidth: bar ? 0 : 2.5,
                borderRadius: bar ? 6 : 0,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHitRadius: 12,
                tension: 0.3,
                ...ds,
            };
        });

        const scales = {
            y: {
                beginAtZero: config.beginAtZero ?? bar,
                ticks: { callback: (v) => fmt(v), maxTicksLimit: 6 },
                grid: { color: ui.grid },
                border: { display: false },
            },
            x: { grid: { display: false }, border: { color: ui.grid } },
        };

        if (config.timeAxis) {
            scales.x = { ...scales.x, type: 'time', time: { unit: 'month', tooltipFormat: 'dd/MM/yyyy' } };
        }

        charts.push(
            new Chart(canvas, {
                type: config.type || 'line',
                data: { labels: config.labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'nearest', intersect: false },
                    plugins: {
                        legend: {
                            display: datasets.length > 1,
                            position: 'bottom',
                            labels: { usePointStyle: true, pointStyle: 'circle', boxHeight: 7, padding: 16 },
                        },
                        tooltip: {
                            backgroundColor: ui.tooltip,
                            padding: 10,
                            cornerRadius: 10,
                            usePointStyle: true,
                            callbacks: { label: (ctx) => ` ${ctx.dataset.label ?? ''}: ${fmt(ctx.parsed.y)}` },
                        },
                    },
                    scales,
                },
            }),
        );
    });
}

window.addEventListener('theme-changed', initCharts);

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
    const bulk = form.querySelector('input[type="checkbox"][name="bulk"]')?.checked;
    const pack = bulk ? 1 : parseInt(form.querySelector('[name="pack_qty"]')?.value || '1', 10);
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
