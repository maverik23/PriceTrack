@extends('layouts.app')
@section('title', 'Reporte de bienes')

@section('content')
<div class="mb-6">
    <h1 class="text-3xl font-bold text-slate-900">Reporte de bienes</h1>
    <p class="text-sm text-slate-500">Mantenimiento por año, lo que cuesta tener cada bien, servicios próximos y garantías.</p>
</div>

<div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <section class="card overflow-x-auto p-0">
        <h2 class="card-title px-5 pt-5 pb-2">Servicios vencidos y próximos</h2>
        <table class="table">
            <tbody>
            @forelse ($plans->filter(fn ($p) => in_array($p->status(), ['overdue', 'soon'])) as $plan)
                <tr>
                    <td><a href="{{ route('assets.show', $plan->asset) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $plan->asset->name }}</a>
                        <div class="text-xs text-slate-500">{{ $plan->name }}</div></td>
                    <td class="text-sm">@include('assets._plan-status') <div class="text-xs text-slate-600">{{ $plan->dueLabel() }}</div></td>
                    <td class="text-right tabular-nums">{{ money($plan->estimated_cost) }}</td>
                </tr>
            @empty
                <tr><td class="py-8 text-center text-sm text-slate-500">Todo al día en los próximos {{ \App\Models\MaintenancePlan::SOON_DAYS }} días.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <section class="card overflow-x-auto p-0">
        <h2 class="card-title px-5 pt-5 pb-2">Garantías que vencen en {{ \App\Services\AssetReport::WARRANTY_DAYS }} días</h2>
        <table class="table">
            <tbody>
            @forelse ($summary['warranties'] as $asset)
                <tr>
                    <td><a href="{{ route('assets.show', $asset) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $asset->name }}</a></td>
                    <td class="text-right text-sm whitespace-nowrap text-amber-700">{{ $asset->warranty_until->translatedFormat('d M Y') }}
                        <div class="text-xs text-slate-500">en {{ (int) today()->diffInDays($asset->warranty_until) }} días</div></td>
                </tr>
            @empty
                <tr><td class="py-8 text-center text-sm text-slate-500">Ninguna garantía vence pronto.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>

<section class="card overflow-x-auto p-0">
    <h2 class="card-title px-5 pt-5 pb-2">Mantenimiento por año y costo de tener cada bien</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Bien</th>
            @foreach ($table['years'] as $year)<th class="text-right">{{ $year }}</th>@endforeach
            <th class="text-right">Mantenimiento</th><th class="text-right">Valor</th><th class="text-right">Contra compra</th><th class="text-right" title="Compra + mantenimiento − valor de hoy (o lo recibido)">Costo de tenerlo</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($table['rows'] as $row)
            <tr>
                <td><a href="{{ route('assets.show', $row['asset']) }}" class="font-medium text-slate-900 hover:text-emerald-700">{{ $row['asset']->name }}</a>
                    @unless ($row['asset']->isActive())<div class="text-xs text-slate-500">{{ $row['asset']->statusLabel() }}</div>@endunless</td>
                @foreach ($table['years'] as $year)
                    <td class="text-right tabular-nums {{ $row['byYear'][$year] ? '' : 'text-slate-300' }}">{{ $row['byYear'][$year] ? money($row['byYear'][$year], 0) : '—' }}</td>
                @endforeach
                <td class="text-right font-semibold tabular-nums">{{ money($row['total'], 0) }}</td>
                <td class="text-right tabular-nums">{{ money($row['value'], 0) }}</td>
                <td class="text-right tabular-nums {{ $row['gain'] === null ? '' : ($row['gain'] >= 0 ? 'text-emerald-700' : 'text-red-600') }}">{{ $row['gain'] === null ? '—' : money($row['gain'], 0) }}</td>
                <td class="text-right font-semibold tabular-nums">{{ money($row['ownership'], 0) }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ count($table['years']) + 5 }}" class="py-8 text-center text-slate-500">Sin bienes todavía.</td></tr>
        @endforelse
        </tbody>
        @if ($table['rows']->isNotEmpty())
            <tfoot>
            <tr class="font-semibold">
                <td class="px-3 py-2">Total</td>
                @foreach ($table['years'] as $year)<td class="px-3 py-2 text-right tabular-nums">{{ money($table['totals'][$year], 0) }}</td>@endforeach
                <td class="px-3 py-2 text-right tabular-nums">{{ money($table['rows']->sum('total'), 0) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money($table['rows']->sum('value'), 0) }}</td>
                <td></td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money($table['rows']->sum('ownership'), 0) }}</td>
            </tr>
            </tfoot>
        @endif
    </table>
</section>
@endsection
