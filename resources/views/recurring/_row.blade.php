{{--
    Un pago recurrente: su estado, el último recibo pagado (monto, lectura, consumo y precio por
    unidad) y el siguiente, con el formulario para registrar su pago. $item
--}}
@php
    $history = $item->history();
    $last = $history[0] ?? null;
    $unit = $item->meter_unit;
    $status = $item->status();
@endphp
<li class="space-y-2 px-4 py-3">
    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <div class="min-w-0">
            <a href="{{ route('recurring.edit', $item) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $item->name }}</a>
            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                @include('recurring._status')
                <span>{{ $item->intervalLabel() }}</span>
                @if ($item->category)<span class="badge badge-muted">{{ $item->category }}</span>@endif
                @if ($item->debt)<a href="{{ route('debts.show', $item->debt) }}" class="link">{{ $item->debt->name }}</a>@endif
            </div>
        </div>
        <div class="text-right tabular-nums">
            <div class="font-semibold">{{ money($item->amount) }}</div>
            <div class="text-xs text-slate-500" title="El monto del último recibo pagado (o el que escribiste): se propone para el siguiente"><span>de referencia</span>@if ($item->every_unit !== 'month' || $item->every !== 1) · {{ money($item->monthlyAmount()) }}/mes @endif</div>
        </div>
    </div>

    {{-- Último recibo pagado --}}
    @if ($last)
        @php $p = $last['payment']; @endphp
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl bg-emerald-50/60 px-3 py-2 text-xs ring-1 ring-emerald-100">
            <span class="font-semibold text-emerald-800">✓ Pagado {{ $p->paid_on->translatedFormat('d M') }}</span>
            <span class="text-slate-600">recibo del {{ ($p->due_on ?? $p->paid_on)->translatedFormat('d M') }}</span>
            <span class="font-semibold text-slate-900 tabular-nums">{{ money($p->amount) }}</span>
            @if ($unit && $p->reading !== null)
                <span class="text-slate-600 tabular-nums">lectura {{ num($p->reading) }} {{ $unit }}</span>
                @if ($last['consumption'] !== null)
                    <span class="text-slate-600 tabular-nums">consumo {{ num($last['consumption']) }} {{ $unit }}</span>
                @endif
                @if ($last['unit_price'] !== null)
                    <span class="font-semibold text-slate-800 tabular-nums">{{ money($last['unit_price']) }}/{{ $unit }}</span>
                @endif
            @endif
            <form method="POST" action="{{ route('paid.destroy', $p) }}" class="ml-auto" onsubmit="return confirm('¿Deshacer este pago? El recibo vuelve a quedar pendiente.')">
                @csrf @method('DELETE')
                <button class="link text-xs text-slate-500 hover:text-red-600">Deshacer</button>
            </form>
        </div>
    @endif

    {{-- El siguiente recibo y su pago --}}
    @if ($item->active)
        <form method="POST" action="{{ route('recurring.pay', $item) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <div class="min-w-36 flex-1 text-xs">
                <span class="{{ $status === 'overdue' ? 'font-semibold text-red-700' : ($status === 'soon' ? 'font-semibold text-amber-700' : 'text-slate-500') }}">
                    {{ $status === 'overdue' ? 'Vencido:' : 'Próximo recibo:' }} {{ $item->next_due_on->translatedFormat('D d M') }}
                </span>
            </div>
            <label class="w-28">
                <span class="sr-only">Monto de este recibo de {{ $item->name }}</span>
                <input name="amount" type="number" step="0.01" min="0.01" class="input py-1.5 text-sm" placeholder="{{ num($item->amount) }}" title="Monto de este recibo (vacío = {{ money($item->amount) }})">
            </label>
            @if ($item->asksReading())
                {{-- Lectura final junto al monto: con ella se calcula el consumo y el precio por unidad --}}
                <label class="w-32">
                    <span class="sr-only">Lectura final{{ $unit ? ' en '.$unit : '' }} de {{ $item->name }}</span>
                    <input name="reading" type="number" step="0.001" min="0" inputmode="decimal" class="input py-1.5 text-sm"
                           placeholder="Lectura{{ $unit ? ' '.$unit : '' }}" title="Lectura final del periodo que pagas{{ $unit ? ', en '.$unit : '' }} (vacía = sin lectura)">
                </label>
                @unless ($unit)
                    @once
                        <datalist id="rp-row-units"><option value="kWh"><option value="m³"><option value="L"></datalist>
                    @endonce
                    {{-- Aún sin unidad: se propone por el nombre y se guarda con la primera lectura --}}
                    <label class="w-20">
                        <span class="sr-only">Unidad de la lectura</span>
                        <input name="meter_unit" value="{{ $item->guessMeterUnit() }}" maxlength="12" list="rp-row-units" class="input py-1.5 text-sm" title="Unidad de la lectura (se guarda con la primera)">
                    </label>
                @endunless
            @endif
            <button class="btn btn-secondary btn-sm" aria-label="Registrar pago de {{ $item->name }}"><x-icon name="check" class="size-4" /> Pagar</button>
        </form>
    @endif
</li>
