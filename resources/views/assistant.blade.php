@extends('layouts.app')
@section('title', 'Asistente (MCP)')

@section('content')
@php
    $endpoint = url('/mcp');
    $badge = fn (array $t) => $t['destructive'] ? ['borra', 'badge-up'] : ($t['read_only'] ? ['consulta', 'badge-muted'] : ['escribe', 'badge-cheap']);
@endphp
<div class="mb-6">
    <h1 class="text-3xl font-bold text-slate-900">Asistente (MCP)</h1>
    <p class="max-w-3xl text-sm text-slate-500">
        Conecta un asistente de IA (Claude, u otro que hable MCP) y pídele cosas en lenguaje normal: «¿dónde sale más barata la leche?»,
        «agrega huevo a la lista del sábado», «pagué la luz, $640, lectura 12,345 kWh», «me llegó el sueldo». Usa los mismos cálculos que la app.
    </p>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="card">
        <div class="stat-label">Herramientas</div>
        <div class="stat-value">{{ $counts['total'] }}</div>
        <div class="mt-1 text-xs text-slate-500">{{ $counts['read'] }} consultan · {{ $counts['write'] }} escriben</div>
    </div>
    <div class="card sm:col-span-2">
        <div class="stat-label">Estado</div>
        @if ($enabled)
            <div class="mt-1 font-medium text-emerald-700">Activo en <code class="rounded bg-slate-100 px-1.5 py-0.5 text-sm text-slate-800">{{ $endpoint }}</code></div>
            <div class="mt-1 text-xs text-slate-500">{{ $hasToken ? 'Pide el token (MCP_TOKEN) en el encabezado Authorization: Bearer.' : 'Sin token: cualquiera en tu red puede usarlo. Para pedir uno, define MCP_TOKEN en el servidor.' }}</div>
        @else
            <div class="mt-1 font-medium text-amber-700">Apagado (MCP_ENABLED=false).</div>
            <div class="mt-1 text-xs text-slate-500">Sigue disponible en el servidor por stdio: <code>php artisan mcp:start pricetrack</code>.</div>
        @endif
    </div>
</div>

<section class="card mb-6 space-y-3">
    <h2 class="card-title">Cómo conectarlo</h2>
    <ol class="list-inside list-decimal space-y-2 text-sm text-slate-700">
        <li>
            <strong>Claude Code</strong> (terminal):
            <pre class="mt-1 overflow-x-auto rounded-lg bg-slate-100 p-3 text-xs text-slate-800">claude mcp add --transport http pricetrack {{ $endpoint }}{{ $hasToken ? ' --header "Authorization: Bearer TU_TOKEN"' : '' }}</pre>
        </li>
        <li>
            <strong>Otros clientes</strong> (Claude Desktop, Cursor…): agrega un servidor MCP de tipo HTTP con la dirección
            <code class="rounded bg-slate-100 px-1">{{ $endpoint }}</code>{{ $hasToken ? ' y el encabezado Authorization: Bearer con tu token' : '' }}.
            Si el cliente solo acepta comandos, usa <code class="rounded bg-slate-100 px-1">npx mcp-remote {{ $endpoint }}</code>.
        </li>
        <li>Pídele «¿qué puedes hacer en PriceTrack?»: usará <code class="rounded bg-slate-100 px-1">get-help</code>, que da esta misma guía.</li>
        <li>Si una herramienta nueva no aparece después de actualizar PriceTrack, desconecta y vuelve a conectar el cliente para que pida otra vez la lista.</li>
    </ol>
</section>

<div class="space-y-6">
    @foreach ($sections as $key => $section)
        @php $writes = collect($section['tools'])->where('read_only', false)->count(); @endphp
        <details class="card group p-0" data-nav="{{ $key === 'general' ? 'inicio' : $key }}" @if ($key === 'general') open @endif>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-3 px-4 py-3">
                <div>
                    <h2 class="card-title">{{ $section['title'] }} <span class="text-sm font-normal text-slate-400">· {{ count($section['tools']) }} herramientas, {{ $writes }} escriben</span></h2>
                    <p class="text-sm text-slate-500">{{ $section['summary'] }}</p>
                </div>
                <x-icon name="chevron-right" class="mt-1 size-5 shrink-0 text-slate-400 transition group-open:rotate-90" />
            </summary>
            <ul class="divide-y divide-slate-100 border-t border-slate-100">
                @foreach ($section['tools'] as $tool)
                    @php [$label, $class] = $badge($tool); @endphp
                    <li class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <code class="text-sm font-semibold text-slate-900">{{ $tool['name'] }}</code>
                            <span class="badge {{ $class }}">{{ $label }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ $tool['description'] }}</p>
                        @if ($tool['params'])
                            <p class="mt-1 flex flex-wrap gap-1 text-xs text-slate-500">
                                @foreach ($tool['params'] as $param)
                                    <code class="rounded bg-slate-100 px-1.5 py-0.5 {{ $param['required'] ? 'font-semibold text-slate-800' : '' }}" title="{{ $param['description'] }}">{{ $param['name'] }}{{ $param['required'] ? '*' : '' }}</code>
                                @endforeach
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endforeach
    <p class="text-xs text-slate-500">* = obligatorio. Pasa el cursor sobre un parámetro para ver qué es.</p>
</div>
@endsection
