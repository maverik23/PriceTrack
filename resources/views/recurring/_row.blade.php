{{-- Un pago recurrente con su botón de pagado. $item --}}
<li class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
    <div class="min-w-0 flex-1">
        <a href="{{ route('recurring.edit', $item) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $item->name }}</a>
        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
            @include('recurring._status')
            <span class="{{ $item->status() === 'overdue' ? 'font-semibold text-red-600' : '' }}">{{ ucfirst($item->next_due_on->translatedFormat('D d M')) }}</span>
            <span>{{ $item->intervalLabel() }}</span>
            @if ($item->category)<span class="badge badge-muted">{{ $item->category }}</span>@endif
            @if ($item->debt)<a href="{{ route('debts.show', $item->debt) }}" class="link">{{ $item->debt->name }}</a>@endif
        </div>
    </div>
    <div class="text-right tabular-nums">
        <div class="font-semibold">{{ money($item->amount) }}</div>
        @if ($item->every_unit !== 'month' || $item->every !== 1)<div class="text-xs text-slate-500">{{ money($item->monthlyAmount()) }}/mes</div>@endif
    </div>
    @if ($item->active)
        <form method="POST" action="{{ route('recurring.pay', $item) }}">
            @csrf
            <button class="btn btn-secondary btn-sm" aria-label="Marcar pagado: {{ $item->name }}">✓ Pagado</button>
        </form>
    @endif
</li>
