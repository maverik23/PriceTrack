{{-- Pestaña Pagos: lo pagado en el mes, la serie, registrar uno único y los pagos recurrentes. --}}
@php
    $kindIcon = ['recurring' => 'repeat', 'one_time' => 'receipt-text', 'debt' => 'credit-card'];
    $payDate = $isCurrentMonth || $month->isFuture() ? today()->toDateString() : $month->endOfMonth()->toDateString();
@endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="min-w-0 space-y-6 lg:col-span-2">
        {{-- Lo pagado en el mes --}}
        <section class="card p-0">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 px-4 py-3">
                <h2 class="card-title">Pagado en {{ $month->translatedFormat('F') }}</h2>
                <span class="text-sm text-slate-500">
                    {{ money($r['paymentsRecurring']) }} recurrentes · {{ money($r['paymentsOneTime']) }} únicos
                    @if ($r['paymentsDebt'] > 0) · {{ money($r['paymentsDebt']) }} abonos @endif
                </span>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($r['paid'] as $paid)
                    @php $kind = $paid->kind(); @endphp
                    <li class="flex items-center gap-3 px-4 py-2.5">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full {{ $kind === 'one_time' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600' }}">
                            <x-icon :name="$kindIcon[$kind]" class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            @if ($paid->recurringPayment)
                                <a href="{{ route('recurring.edit', $paid->recurringPayment) }}" class="block truncate font-medium text-slate-900 hover:text-emerald-700">{{ $paid->label() }}</a>
                            @elseif ($paid->debt)
                                <a href="{{ route('debts.show', $paid->debt) }}" class="block truncate font-medium text-slate-900 hover:text-emerald-700">{{ $paid->label() }}</a>
                            @else
                                <div class="truncate font-medium text-slate-900">{{ $paid->label() }}</div>
                            @endif
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500">
                                <span>{{ ucfirst($paid->paid_on->translatedFormat('D d M')) }}</span>
                                @if ($paid->category ?? $paid->recurringPayment?->category)<span class="badge badge-muted">{{ $paid->categoryLabel() }}</span>@endif
                                @if ($kind === 'recurring')<span title="De un pago recurrente">↻</span>@endif
                                @if ($paid->notes)<span class="truncate">{{ $paid->notes }}</span>@endif
                            </div>
                        </div>
                        <span class="font-semibold text-slate-900 tabular-nums">{{ money($paid->amount) }}</span>
                        <form method="POST" action="{{ route('paid.destroy', $paid) }}" onsubmit="return confirm('{{ $kind === 'recurring' ? '¿Deshacer este pago? El recibo vuelve a quedar pendiente.' : '¿Eliminar este pago?' }}')">
                            @csrf @method('DELETE')
                            <button class="icon-btn size-8 text-slate-400 hover:text-red-600" aria-label="Eliminar {{ $paid->label() }}" title="Eliminar"><x-icon name="trash" class="size-4" /></button>
                        </form>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-slate-500">Ningún pago registrado en {{ $month->translatedFormat('F') }}.</li>
                @endforelse
            </ul>
            @if ($r['paymentsByCategory']->count() > 1)
                <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-3 text-xs text-slate-600">
                    @foreach ($r['paymentsByCategory'] as $category => $amount)
                        <span>{{ $category }}: <span class="font-semibold tabular-nums">{{ money($amount) }}</span></span>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Recurrentes: el renglón (con monto y lectura al pagar) necesita lo ancho --}}
        <section class="card p-0">
            <div class="flex items-baseline justify-between gap-2 border-b border-slate-100 px-4 py-3">
                <h2 class="card-title">Pagos recurrentes</h2>
                <span class="text-xs text-slate-500">{{ money($expectedPayments) }}/mes · {{ money($expectedPayments * 12, 0) }} al año</span>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($recurring as $item)
                    @include('recurring._row')
                @empty
                    <li class="px-4 py-6 text-center text-sm text-slate-500">Agrega la luz, el agua, el internet o una mensualidad: lo que pagas cada cierto tiempo.</li>
                @endforelse
            </ul>
            @if ($recurringInactive->isNotEmpty())
                <details class="border-t border-slate-100 px-4 py-2 text-sm">
                    <summary class="cursor-pointer text-slate-600">Pausados ({{ $recurringInactive->count() }})</summary>
                    <ul class="mt-2 space-y-1">
                        @foreach ($recurringInactive as $item)
                            <li><a class="link" href="{{ route('recurring.edit', $item) }}">{{ $item->name }}</a> <span class="text-slate-500">· {{ money($item->amount) }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        {{-- Últimos 12 meses --}}
        @if ($paymentSeries->sum('total') > 0)
            <section class="card">
                <h2 class="card-title">Pagos, últimos 12 meses</h2>
                <div class="mt-3 h-64">
                    <canvas data-chart="{{ json_encode(['type' => 'bar', 'format' => 'money', 'stacked' => true, 'labels' => $paymentSeries->pluck('label'), 'datasets' => [
                        ['label' => 'Recurrentes', 'data' => $paymentSeries->pluck('recurring')->map(fn ($v) => round($v, 2)), 'color' => 'ciruela'],
                        ['label' => 'Únicos', 'data' => $paymentSeries->pluck('one_time')->map(fn ($v) => round($v, 2)), 'color' => 'chile'],
                    ]]) }}"></canvas>
                </div>
            </section>
        @endif
    </div>

    <div class="min-w-0 space-y-6">
        {{-- Registrar uno único --}}
        <form method="POST" action="{{ route('paid.store') }}" class="card space-y-3">
            @csrf
            <h2 class="card-title">Registrar pago único</h2>
            <p class="-mt-2 text-xs text-slate-500">Gasolina, una comida fuera, una compra: lo que pagas una sola vez.</p>
            <div>
                <label class="label" for="pay-name">Concepto</label>
                <input id="pay-name" name="name" value="{{ old('name') }}" class="input" maxlength="120" required placeholder="Ej. Gasolina, cena, regalo de cumpleaños">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="pay-amount">Monto</label>
                    <input id="pay-amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="input" required placeholder="$">
                </div>
                <div>
                    <label class="label" for="pay-date">Fecha</label>
                    <input id="pay-date" name="paid_on" type="date" value="{{ old('paid_on', $payDate) }}" max="{{ today()->toDateString() }}" class="input" required>
                </div>
            </div>
            <x-combobox name="category" id="pay-category" label="Categoría" :options="$paymentCategories" :value="old('category')" noun="categoría nueva" placeholder="Ej. Gasolina, Comida fuera" />
            <div>
                <label class="label" for="pay-notes">Notas <span class="font-normal text-slate-400">opcional</span></label>
                <input id="pay-notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="255">
            </div>
            <button class="btn btn-primary w-full">Registrar</button>
        </form>

        {{-- Nuevo recurrente --}}
        <details class="card p-0" @if ($errors->hasAny(['every', 'every_unit', 'next_due_on', 'debt_id', 'meter_unit'])) open @endif>
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-emerald-700">+ Nuevo pago recurrente</summary>
                <form method="POST" action="{{ route('recurring.store') }}" class="space-y-3 px-4 pb-4">
                    @csrf
                    @php $categories = $paymentCategories; @endphp
                    @include('recurring._fields')
                    <button class="btn btn-primary w-full">Agregar</button>
                </form>
            </details>

    </div>
</div>
