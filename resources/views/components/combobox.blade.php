{{--
    Input con autocompletar de valores existentes; lo que no exista se guarda como nuevo.
    Uso: <x-combobox name="brand" label="Marca" :options="$brands" :value="old('brand', $product->brand)" noun="marca nueva" placeholder="Ej. Bimbo" />
--}}
@props(['name', 'label', 'options' => [], 'value' => null, 'noun' => 'nueva', 'placeholder' => null])

@php($config = ['options' => collect($options)->values()->all(), 'value' => (string) $value, 'noun' => $noun])

<div class="relative" x-data="combobox({{ Js::from($config['options']) }}, {{ Js::from($config['value']) }}, {{ Js::from($config['noun']) }})">
    <label class="label" for="{{ $name }}">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="text" class="input" autocomplete="off" placeholder="{{ $placeholder }}"
           value="{{ $value }}" x-model="value"
           role="combobox" :aria-expanded="open" aria-autocomplete="list" aria-controls="{{ $name }}-options"
           @focus="show()" @click="show()" @input="show()" @keydown="onKey($event)" @blur="settle()">

    <div id="{{ $name }}-options" role="listbox" x-show="open && options.length" x-cloak @mousedown.prevent
         class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-slate-200">
        <template x-for="(opt, i) in options" :key="opt.key">
            <button type="button" tabindex="-1" role="option" :aria-selected="i === hi"
                    class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm"
                    :class="i === hi ? 'bg-emerald-50' : 'hover:bg-slate-50'"
                    @mouseenter="hi = i" @click="choose(opt)">
                <span class="truncate" :class="opt.isNew ? 'font-medium text-emerald-700' : 'text-slate-900'" x-text="opt.isNew ? '+ ' + opt.label : opt.label"></span>
                <span class="shrink-0 text-xs text-emerald-600" x-show="!opt.isNew && opt.value === match">✓</span>
            </button>
        </template>
    </div>
    <p class="mt-1 h-4 text-xs text-slate-500" x-text="hint"></p>
</div>
