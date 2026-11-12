@php
    $today = today();
    $presets = [
        '3 meses' => [$today->copy()->subMonths(3)->addDay(), $today],
        '6 meses' => [$today->copy()->subMonths(6)->addDay(), $today],
        '12 meses' => [$today->copy()->subYear()->addDay(), $today],
        'Este año' => [$today->copy()->startOfYear(), $today],
        'Año pasado' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
        '5 años' => [$today->copy()->subYears(5)->addDay(), $today],
    ];
@endphp
<form method="GET" class="card mb-6 flex flex-wrap items-end gap-3">
    <div>
        <label class="label" for="from">Desde</label>
        <input id="from" type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="input">
    </div>
    <div>
        <label class="label" for="to">Hasta</label>
        <input id="to" type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="input">
    </div>
    <button class="btn btn-primary">Aplicar</button>
    <div class="flex flex-wrap gap-1 sm:ml-auto">
        @foreach ($presets as $label => [$f, $t])
            @php($active = $from->isSameDay($f) && $to->isSameDay($t))
            <a href="{{ request()->url() }}?from={{ $f->format('Y-m-d') }}&to={{ $t->format('Y-m-d') }}"
               class="btn btn-sm {{ $active ? 'bg-emerald-600 text-white' : 'btn-secondary' }}">{{ $label }}</a>
        @endforeach
    </div>
</form>
