@switch($item->status())
    @case('overdue')<span class="badge badge-up">Vencido</span>@break
    @case('soon')<span class="badge bg-amber-50 text-amber-800">Pronto</span>@break
    @case('inactive')<span class="badge badge-muted">Pausado</span>@break
    @default<span class="badge badge-down">Al corriente</span>
@endswitch
