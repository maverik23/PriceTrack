{{--
    Calendario de tareas del mes (App\Support\TaskCalendar): conteos del mes, cuadrícula de
    lunes a domingo con lo de cada día (hechas, pendientes, vencidas y repeticiones proyectadas)
    y, al tocar un día, sus tareas para marcarlas y agregar otra en esa fecha.
    Variables: $calendar, $baseQuery (lista elegida), $showList.
--}}
@php
    $c = $calendar;
    $month = $c['month'];
    $selectedDefault = $c['days']->firstWhere('isToday', true)['key'] ?? $month->toDateString();
    $pct = $c['completion'] === null ? null : (int) round($c['completion'] * 100);
@endphp
<div x-data="{ selected: {{ Js::from($selectedDefault) }} }" class="space-y-4">
    {{-- Conteos del mes --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card p-4">
            <div class="stat-label">Programadas</div>
            <div class="stat-value">{{ $c['counts']['total'] }}</div>
            <div class="text-xs text-slate-500">en {{ $month->translatedFormat('F') }}</div>
        </div>
        <div class="card p-4">
            <div class="stat-label">Hechas</div>
            <div class="stat-value text-emerald-700">{{ $c['counts']['done'] }}</div>
            <div class="text-xs text-slate-500">{{ $pct === null ? 'nada vencía aún' : $pct.' % de lo que tocaba' }}</div>
        </div>
        <div class="card p-4">
            <div class="stat-label">Pendientes</div>
            <div class="stat-value">{{ $c['counts']['pending'] }}</div>
            <div class="text-xs text-slate-500">de hoy en adelante</div>
        </div>
        <div class="card p-4">
            <div class="stat-label">Vencidas</div>
            <div class="stat-value {{ $c['counts']['overdue'] ? 'text-red-700' : '' }}">{{ $c['counts']['overdue'] }}</div>
            <div class="text-xs text-slate-500">no se hicieron a tiempo</div>
        </div>
    </div>

    <section class="card p-0">
        {{-- Mes --}}
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <h2 class="card-title capitalize">{{ $month->translatedFormat('F Y') }}</h2>
            <div class="flex items-center gap-1">
                <a href="{{ route('tasks.index', $baseQuery + ['month' => $c['prev']]) }}" class="icon-btn" aria-label="Mes anterior"><x-icon name="chevron-left" class="size-5" /></a>
                @unless ($month->isSameMonth(today()))
                    <a href="{{ route('tasks.index', $baseQuery) }}" class="btn btn-ghost btn-sm">Hoy</a>
                @endunless
                <a href="{{ route('tasks.index', $baseQuery + ['month' => $c['next']]) }}" class="icon-btn" aria-label="Mes siguiente"><x-icon name="chevron-right" class="size-5" /></a>
            </div>
        </div>

        {{-- Cuadrícula --}}
        <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50 text-center text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
            @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $name)
                <div class="py-1.5">{{ $name }}</div>
            @endforeach
        </div>
        <div class="grid grid-cols-7">
            @foreach ($c['days'] as $day)
                @php
                    $n = $day['counts'];
                    $items = $day['tasks']->map(fn ($t) => ['title' => $t->title, 'status' => $t->status()])
                        ->concat(collect($day['projected'])->map(fn ($p) => ['title' => $p['task']->title, 'status' => 'projected']));
                @endphp
                <button type="button" @click="selected = '{{ $day['key'] }}'"
                        class="group relative flex min-h-16 flex-col gap-1 border-r border-b border-slate-100 p-1 text-left transition hover:bg-slate-50 sm:min-h-28 sm:p-1.5 {{ $loop->iteration % 7 === 0 ? 'border-r-0' : '' }} {{ $day['inMonth'] ? '' : 'bg-slate-50/60' }}"
                        :class="selected === '{{ $day['key'] }}' && 'bg-emerald-50/60 ring-2 ring-inset ring-emerald-500'"
                        :aria-pressed="selected === '{{ $day['key'] }}'"
                        aria-label="{{ ucfirst($day['date']->translatedFormat('l d \d\e F')) }}: {{ $n['total'] }} {{ $n['total'] === 1 ? 'tarea' : 'tareas' }}{{ $n['done'] ? ', '.$n['done'].' hechas' : '' }}{{ $n['overdue'] ? ', '.$n['overdue'].' vencidas' : '' }}">
                    <div class="flex items-center justify-between gap-1">
                        <span class="grid size-6 place-items-center rounded-full text-xs font-semibold tabular-nums
                            {{ $day['isToday'] ? 'bg-brand text-on-brand' : ($day['inMonth'] ? 'text-slate-800' : 'text-slate-400') }}">{{ $day['date']->day }}</span>
                        @if ($n['total'])
                            <span class="rounded-full px-1.5 text-[11px] font-semibold tabular-nums
                                {{ $n['overdue'] ? 'bg-red-50 text-red-700' : ($n['pending'] ? 'bg-slate-200/70 text-slate-700' : 'bg-emerald-100 text-emerald-800') }}">{{ $n['total'] }}</span>
                        @endif
                    </div>
                    {{-- Títulos (en pantallas medianas en adelante) --}}
                    <ul class="hidden min-w-0 space-y-0.5 sm:block">
                        @foreach ($items->take(3) as $item)
                            <li class="flex min-w-0 items-center gap-1 text-[11px] leading-tight">
                                <span class="size-1.5 shrink-0 rounded-full {{ match ($item['status']) {
                                    'done' => 'bg-emerald-500', 'overdue' => 'bg-red-500', 'projected' => 'border border-slate-400', default => 'bg-slate-400' } }}"></span>
                                <span class="truncate {{ match ($item['status']) {
                                    'done' => 'text-slate-400 line-through', 'overdue' => 'font-medium text-red-700', 'projected' => 'text-slate-400 italic', default => 'text-slate-700' } }}">{{ $item['title'] }}</span>
                            </li>
                        @endforeach
                        @if ($items->count() > 3)
                            <li class="text-[11px] text-slate-500">+{{ $items->count() - 3 }} más</li>
                        @endif
                    </ul>
                    {{-- En celular: puntos por estado --}}
                    <div class="mt-auto flex flex-wrap gap-0.5 sm:hidden">
                        @for ($i = 0; $i < min($n['done'], 3); $i++)<span class="size-1.5 rounded-full bg-emerald-500"></span>@endfor
                        @for ($i = 0; $i < min($n['pending'], 3); $i++)<span class="size-1.5 rounded-full bg-slate-400"></span>@endfor
                        @for ($i = 0; $i < min($n['overdue'], 3); $i++)<span class="size-1.5 rounded-full bg-red-500"></span>@endfor
                        @if (count($day['projected']))<span class="size-1.5 rounded-full border border-slate-400"></span>@endif
                    </div>
                </button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2 text-xs text-slate-500">
            <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-emerald-500"></span> hecha</span>
            <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-slate-400"></span> pendiente</span>
            <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-red-500"></span> vencida</span>
            <span class="flex items-center gap-1"><span class="size-2 rounded-full border border-slate-400"></span> repetición futura</span>
            @if ($c['projectedCount'])<span>· {{ $c['projectedCount'] }} {{ $c['projectedCount'] === 1 ? 'repetición proyectada' : 'repeticiones proyectadas' }} este mes</span>@endif
            @if ($c['overdueBefore'])
                <a class="link" href="{{ route('tasks.index', $baseQuery + ['view' => 'list']) }}">· {{ $c['overdueBefore'] }} vencidas de meses anteriores</a>
            @endif
            @if ($c['undated'])
                <a class="link" href="{{ route('tasks.index', $baseQuery + ['view' => 'list']) }}">· {{ $c['undated'] }} sin fecha</a>
            @endif
        </div>
    </section>

    {{-- El día elegido: sus tareas y agregar otra en esa fecha --}}
    @foreach ($c['days'] as $day)
        @php $n = $day['counts']; @endphp
        <section class="card p-0" x-show="selected === '{{ $day['key'] }}'" x-cloak aria-labelledby="day-{{ $day['key'] }}">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
                <h3 id="day-{{ $day['key'] }}" class="card-title">{{ ucfirst($day['date']->translatedFormat('l d \d\e F')) }}</h3>
                @if ($day['isToday'])<span class="badge badge-cheap">Hoy</span>@endif
                <span class="ml-auto flex flex-wrap gap-1 text-xs">
                    <span class="badge badge-muted">{{ $n['total'] }} {{ $n['total'] === 1 ? 'tarea' : 'tareas' }}</span>
                    @if ($n['done'])<span class="badge badge-cheap">{{ $n['done'] }} {{ $n['done'] === 1 ? 'hecha' : 'hechas' }}</span>@endif
                    @if ($n['overdue'])<span class="badge badge-up">{{ $n['overdue'] }} {{ $n['overdue'] === 1 ? 'vencida' : 'vencidas' }}</span>@endif
                </span>
            </div>
            @if ($day['tasks']->isNotEmpty())
                <ul class="divide-y divide-slate-100">
                    @foreach ($day['tasks'] as $task)
                        @include('tasks._row')
                    @endforeach
                </ul>
            @endif
            @if (count($day['projected']))
                <ul class="divide-y divide-slate-100 border-t border-slate-100">
                    @foreach ($day['projected'] as $p)
                        <li class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-500">
                            <span class="grid size-5 shrink-0 place-items-center rounded-full border border-dashed border-slate-300 text-[10px]">↻</span>
                            <span class="min-w-0 flex-1 truncate italic">{{ $p['task']->title }}</span>
                            <span class="shrink-0 text-xs">se repite {{ mb_strtolower($p['task']->repeatLabel()) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($day['tasks']->isEmpty() && ! count($day['projected']))
                <p class="px-4 py-4 text-sm text-slate-500">Nada para este día.</p>
            @endif
            <form method="POST" action="{{ route('tasks.store') }}" class="flex gap-2 border-t border-slate-100 px-4 py-3">
                @csrf
                <input type="hidden" name="due_on" value="{{ $day['key'] }}">
                @if (! empty($baseQuery['list']) && $baseQuery['list'] !== 'none')
                    <input type="hidden" name="task_list_id" value="{{ $baseQuery['list'] }}">
                @endif
                <label class="sr-only" for="add-{{ $day['key'] }}">Nueva tarea para el {{ $day['date']->translatedFormat('d \d\e F') }}</label>
                <input id="add-{{ $day['key'] }}" name="title" class="input" maxlength="200" required placeholder="Agregar tarea para este día…">
                <button class="btn btn-primary">Agregar</button>
            </form>
        </section>
    @endforeach
</div>
