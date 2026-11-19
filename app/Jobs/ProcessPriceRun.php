<?php

namespace App\Jobs;

use App\Models\PriceRun;
use App\Models\ProductLink;
use App\Services\BranchRequiredException;
use App\Services\PriceUpdater;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Consulta el siguiente enlace de una actualización de precios y se vuelve a encolar para
 * el que sigue: cada trabajo es corto (una tienda), así que no lo corta el límite de tiempo
 * de la cola, y cancelar o reanudar es solo cambiar el registro.
 */
class ProcessPriceRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $runId) {}

    public function handle(PriceUpdater $updater): void
    {
        $run = PriceRun::find($this->runId);
        if (! $run?->isRunning()) {
            return;
        }
        if ($run->cancel_requested) {
            $run->finish(PriceRun::CANCELLED);

            return;
        }
        if ($run->position >= $run->total()) {
            $run->finish(PriceRun::DONE);

            return;
        }

        $link = ProductLink::with(['product', 'provider'])->find($run->link_ids[$run->position]);
        $label = $link ? "{$link->product?->name} — {$link->provider?->name}" : 'Enlace borrado';
        $run->forceFill(['current' => $label])->save();

        if (! $link?->url) {
            $run->addFailure($label, 'Ya no tiene dirección en esa tienda.');
        } else {
            try {
                $result = $updater->update($link);
                $result['ok'] ? $run->ok++ : $run->addFailure($label, $result['message']);
            } catch (BranchRequiredException $e) {
                $run->addFailure($label, $e->getMessage());
            } catch (Throwable $e) {
                report($e);
                $run->addFailure($label, 'Error inesperado al consultar.');
            }
        }

        $run->position++;
        $run->save();

        if ($run->position >= $run->total()) {
            $run->finish(PriceRun::DONE);
        } else {
            self::dispatch($run->id);
        }
    }
}
