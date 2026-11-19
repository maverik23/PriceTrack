{{-- Cambia entre tema automático (el del sistema), claro y oscuro. Lógica en resources/js/theme.js --}}
<button type="button" x-data="themeToggle" @click="cycle()" class="icon-btn" :title="label" :aria-label="label">
    <span x-show="choice === 'auto'"><x-icon name="sun-moon" class="size-[18px]" /></span>
    <span x-show="choice === 'light'" x-cloak><x-icon name="sun" class="size-[18px]" /></span>
    <span x-show="choice === 'dark'" x-cloak><x-icon name="moon" class="size-[18px]" /></span>
</button>
