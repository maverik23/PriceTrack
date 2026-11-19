{{-- Estado del pago recurrente: vencido, por pagar (vence pronto), pagado (el último recibo), sin pagos o pausado. $item --}}
@switch($item->status())
    @case('overdue')<span class="badge badge-up">Vencido</span>@break
    @case('soon')<span class="badge bg-amber-50 text-amber-800">Por pagar</span>@break
    @case('inactive')<span class="badge badge-muted">Pausado</span>@break
    @default
        @if ($item->lastPayment())
            <span class="badge badge-cheap">✓ Pagado</span>
        @else
            <span class="badge badge-muted">Sin pagos</span>
        @endif
@endswitch
