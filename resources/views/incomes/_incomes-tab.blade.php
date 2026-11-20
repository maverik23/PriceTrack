{{-- Pestaña Ingresos: lo recibido en el mes, la serie, registrar uno único y los recurrentes. --}}
@php
    $kindBadge = fn (string $kind) => $kind === 'in_kind' ? 'badge bg-amber-100 text-amber-800' : 'badge badge-muted';
@endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="min-w-0 space-y-6 lg:col-span-2">
        {{-- Lo recibido en el mes --}}
        <section class="card p-0">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 px-4 py-3">
                <h2 class="card-title">Recibido en {{ $month->translatedFormat('F') }}</h2>
                <span class="text-sm text-slate-500">{{ $r['incomes']->count() }} {{ $r['incomes']->count() === 1 ? 'ingreso' : 'ingresos' }}</span>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($r['incomes'] as $income)
                    <li class="flex items-center gap-3 px-4 py-2.5">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full {{ $income->isInKind() ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' }}">
                            <x-icon :name="$income->isInKind() ? 'gift' : 'banknote'" class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-slate-900">{{ $income->name }}</div>
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500">
                                <span>{{ ucfirst($income->received_on->translatedFormat('D d M')) }}</span>
                                @if ($income->category)<span class="badge badge-muted">{{ $income->category }}</span>@endif
                                @if ($income->isInKind())<span class="{{ $kindBadge('in_kind') }}">En especie</span>@endif
                                @if ($income->source)<span title="De un ingreso recurrente">↻</span>@endif
                                @if ($income->notes)<span class="truncate">{{ $income->notes }}</span>@endif
                            </div>
                        </div>
                        <span class="font-semibold text-slate-900 tabular-nums">{{ money($income->amount) }}</span>
                        <form method="POST" action="{{ route('incomes.destroy', $income) }}" onsubmit="return confirm('¿Eliminar este ingreso?')">
                            @csrf @method('DELETE')
                            <button class="icon-btn size-8 text-slate-400 hover:text-red-600" aria-label="Eliminar {{ $income->name }}" title="Eliminar"><x-icon name="trash" class="size-4" /></button>
                        </form>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-slate-500">Nada registrado en {{ $month->translatedFormat('F') }}.</li>
                @endforelse
            </ul>
            @if ($r['byCategory']->count() > 1)
                <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-3 text-xs text-slate-600">
                    @foreach ($r['byCategory'] as $category => $amount)
                        <span>{{ $category }}: <span class="font-semibold tabular-nums">{{ money($amount) }}</span></span>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Últimos 12 meses --}}
        @if ($series->sum('total') > 0)
            <section class="card">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="card-title">Ingresos, últimos 12 meses</h2>
                    @if ($average !== null)<span class="text-xs text-slate-500">promedio {{ money($average) }}/mes</span>@endif
                </div>
                <div class="mt-3 h-64">
                    <canvas data-chart="{{ json_encode(['type' => 'bar', 'format' => 'money', 'stacked' => true, 'labels' => $series->pluck('label'), 'datasets' => [
                        ['label' => 'Dinero', 'data' => $series->pluck('money')->map(fn ($v) => round($v, 2)), 'color' => 'jade'],
                        ['label' => 'En especie', 'data' => $series->pluck('in_kind')->map(fn ($v) => round($v, 2)), 'color' => 'sol'],
                    ]]) }}"></canvas>
                </div>
            </section>
        @endif
    </div>

    <div class="min-w-0 space-y-6">
        {{-- Registrar uno eventual --}}
        <form method="POST" action="{{ route('incomes.store') }}" class="card space-y-3">
            @csrf
            <h2 class="card-title">Registrar ingreso único</h2>
            <p class="-mt-2 text-xs text-slate-500">Una venta, un bono, un regalo: lo que entra una sola vez.</p>
            <div>
                <label class="label" for="in-name">Concepto</label>
                <input id="in-name" name="name" value="{{ old('name') }}" class="input" maxlength="120" required placeholder="Ej. Venta de la bici, bono, regalo">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="in-amount">Monto</label>
                    <input id="in-amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="input" required placeholder="$">
                </div>
                <div>
                    <label class="label" for="in-date">Fecha</label>
                    <input id="in-date" name="received_on" type="date" value="{{ old('received_on', $isCurrentMonth ? today()->toDateString() : $month->toDateString()) }}" class="input" required>
                </div>
            </div>
            <fieldset>
                <legend class="label">Tipo</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (\App\Models\Income::KINDS as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl px-3 py-2 text-sm ring-1 ring-slate-200 has-[:checked]:bg-emerald-50 has-[:checked]:ring-emerald-400">
                            <input type="radio" name="kind" value="{{ $value }}" @checked(old('kind', 'money') === $value)> {{ $label }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-slate-500">En especie: vales, gasolina, un regalo… escribe lo que vale.</p>
            </fieldset>
            <x-combobox name="category" label="Categoría" :options="$categories" :value="old('category')" noun="categoría nueva" placeholder="Ej. Bono, Regalo" />
            <div>
                <label class="label" for="in-notes">Notas <span class="font-normal text-slate-400">opcional</span></label>
                <input id="in-notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="255">
            </div>
            <button class="btn btn-primary w-full">Registrar</button>
        </form>

        {{-- Recurrentes --}}
        <section class="card p-0">
            <div class="flex items-baseline justify-between gap-2 border-b border-slate-100 px-4 py-3">
                <h2 class="card-title">Ingresos recurrentes</h2>
                <span class="text-xs text-slate-500">{{ money($expected['total']) }}/mes</span>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($sources as $item)
                    @php $status = $item->status(); @endphp
                    <li class="space-y-2 px-4 py-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('incomes.sources.edit', $item) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $item->name }}</a>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500">
                                    <span class="{{ $status === 'overdue' ? 'font-semibold text-amber-700' : ($status === 'soon' ? 'font-semibold text-emerald-700' : '') }}">
                                        {{ $status === 'overdue' ? 'Tocaba el ' : '' }}{{ ucfirst($item->next_on->translatedFormat('D d M')) }}
                                    </span>
                                    <span>{{ $item->intervalLabel() }}</span>
                                    @if ($item->kind === 'in_kind')<span class="{{ $kindBadge('in_kind') }}">En especie</span>@endif
                                </div>
                            </div>
                            <div class="text-right tabular-nums">
                                <div class="font-semibold">{{ money($item->amount) }}</div>
                                @if ($item->every_unit !== 'month' || $item->every !== 1)<div class="text-xs text-slate-500">{{ money($item->monthlyAmount()) }}/mes</div>@endif
                            </div>
                        </div>
                        {{-- Recibido: con el monto de siempre o el que llegó --}}
                        <form method="POST" action="{{ route('incomes.sources.receive', $item) }}" class="flex items-center gap-2">
                            @csrf
                            <label class="sr-only" for="rcv-{{ $item->id }}">Monto recibido de {{ $item->name }}</label>
                            <input id="rcv-{{ $item->id }}" name="amount" type="number" step="0.01" min="0.01" class="input py-1.5 text-sm" placeholder="{{ num($item->amount) }}" title="Vacío = el monto de siempre">
                            <button class="btn btn-secondary btn-sm shrink-0" aria-label="Marcar recibido: {{ $item->name }}"><x-icon name="check" class="size-4" /> Recibido</button>
                        </form>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-slate-500">Agrega tu sueldo, una renta o los vales que te dan cada mes.</li>
                @endforelse
            </ul>
            @if ($inactive->isNotEmpty())
                <details class="border-t border-slate-100 px-4 py-2 text-sm">
                    <summary class="cursor-pointer text-slate-600">Pausados ({{ $inactive->count() }})</summary>
                    <ul class="mt-2 space-y-1">
                        @foreach ($inactive as $item)
                            <li><a class="link" href="{{ route('incomes.sources.edit', $item) }}">{{ $item->name }}</a> <span class="text-slate-500">· {{ money($item->amount) }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
            <details class="border-t border-slate-100" @if ($errors->hasAny(['every', 'every_unit', 'next_on'])) open @endif>
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-emerald-700">+ Nuevo ingreso recurrente</summary>
                <form method="POST" action="{{ route('incomes.sources.store') }}" class="space-y-3 px-4 pb-4">
                    @csrf
                    @include('incomes._source-fields')
                    <button class="btn btn-primary w-full">Agregar</button>
                </form>
            </details>
        </section>
    </div>
</div>
