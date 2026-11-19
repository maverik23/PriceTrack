{{-- Distintivo del estado de un plan de mantenimiento. $plan --}}
@switch($plan->status())
    @case('overdue')<span class="badge badge-up">Vencido</span>@break
    @case('soon')<span class="badge bg-amber-50 text-amber-800">Pronto</span>@break
    @case('ok')<span class="badge badge-down">Al día</span>@break
    @default<span class="badge badge-muted">Sin intervalo</span>
@endswitch
