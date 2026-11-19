<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Una actualización de precios en segundo plano ("Actualizar todos los precios"): la corre
 * App\Jobs\ProcessPriceRun enlace por enlace en la cola, así que sigue aunque se cierre o
 * recargue la página. La pantalla lee su avance con GET price-runs/{run}.
 */
#[Fillable(['title', 'status', 'link_ids', 'position', 'ok', 'failures', 'current', 'cancel_requested', 'finished_at'])]
class PriceRun extends Model
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    /** Sin avance en este tiempo se considera detenida (se cayó el proceso de la cola). */
    public const STALE_MINUTES = 5;

    protected function casts(): array
    {
        return [
            'link_ids' => 'array',
            'failures' => 'array',
            'cancel_requested' => 'boolean',
            'finished_at' => 'datetime',
        ];
    }

    /** La que está corriendo (o detenida sin terminar), si hay. */
    public static function active(): ?self
    {
        return static::where('status', self::RUNNING)->latest('id')->first();
    }

    public function total(): int
    {
        return count($this->link_ids ?? []);
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    /** Corriendo pero sin avanzar desde hace rato: el proceso de la cola no la está atendiendo. */
    public function isStalled(): bool
    {
        return $this->isRunning() && $this->updated_at?->lt(now()->subMinutes(self::STALE_MINUTES));
    }

    public function addFailure(string $label, string $message): void
    {
        $this->failures = [...($this->failures ?? []), ['label' => $label, 'message' => $message]];
    }

    public function finish(string $status): void
    {
        $this->forceFill(['status' => $status, 'current' => null, 'finished_at' => now()])->save();
    }

    /** Lo que necesita la pantalla (misma forma que createRun() de resources/js/online-prices.js). */
    public function state(): array
    {
        return [
            'id' => $this->id,
            'title' => match ($this->status) {
                self::DONE => 'Precios actualizados',
                self::CANCELLED => 'Actualización cancelada',
                default => $this->title,
            },
            'status' => $this->status,
            'active' => $this->isRunning(),
            'stalled' => $this->isStalled(),
            'finished' => ! $this->isRunning(),
            'cancelled' => $this->status === self::CANCELLED || $this->cancel_requested,
            'total' => $this->total(),
            'done' => $this->position,
            'ok' => $this->ok,
            'failures' => $this->failures ?? [],
            'current' => $this->current ?? '',
            'url' => route('price-runs.show', $this),
        ];
    }
}
