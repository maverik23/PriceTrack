<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\MaintenancePlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Valor, mantenimiento y garantías de los bienes. */
class AssetReport
{
    public const WARRANTY_DAYS = 60;

    /** @return Collection<int, Asset> */
    public function assets(): Collection
    {
        return Asset::with(['valuations', 'meterReadings', 'maintenanceLogs', 'maintenancePlans.logs', 'maintenancePlans.asset'])
            ->orderBy('name')->get();
    }

    /** Suma del valor de los bienes en una fecha (los vendidos o dados de baja ya no cuentan). */
    public function totalValueOn(CarbonInterface $date, ?Collection $assets = null): float
    {
        return round(($assets ?? $this->assets())->sum(fn (Asset $a) => $a->valueOn($date) ?? 0), 2);
    }

    /**
     * Tarjetas del listado y del inicio.
     *
     * @return array{value: float, maintenanceYear: float, overdue: Collection, soon: Collection, warranties: Collection}
     */
    public function summary(?Collection $assets = null): array
    {
        $assets ??= $this->assets();
        $active = $assets->filter->isActive();
        $plans = $this->plans($active);

        return [
            'value' => $this->totalValueOn(today(), $active),
            'maintenanceYear' => round($assets->sum(fn (Asset $a) => $a->maintenanceCost(today()->year)), 2),
            'overdue' => $plans->filter(fn (MaintenancePlan $p) => $p->status() === 'overdue')->values(),
            'soon' => $plans->filter(fn (MaintenancePlan $p) => $p->status() === 'soon')->values(),
            'warranties' => $active->filter(fn (Asset $a) => $a->warrantyExpiresWithin(self::WARRANTY_DAYS))
                ->sortBy('warranty_until')->values(),
        ];
    }

    /** Planes de mantenimiento de los bienes en uso, del más urgente al menos. */
    public function plans(Collection $assets): Collection
    {
        $order = ['overdue' => 0, 'soon' => 1, 'ok' => 2, 'none' => 3];

        return $assets->filter->isActive()
            ->flatMap(fn (Asset $a) => $a->maintenancePlans->each(fn (MaintenancePlan $p) => $p->setRelation('asset', $a)))
            ->sortBy(fn (MaintenancePlan $p) => [$order[$p->status()], $p->nextDueOn()?->toDateString() ?? '9999'])
            ->values();
    }

    /**
     * Costo de mantenimiento por bien y por año, más el costo de tener cada bien.
     *
     * @return array{years: array<int, int>, rows: Collection, totals: array<int, float>}
     */
    public function maintenanceByYear(?Collection $assets = null): array
    {
        $assets ??= $this->assets();
        // Años seguidos, del primer servicio a este año
        $first = $assets->flatMap(fn (Asset $a) => $a->maintenanceLogs->map(fn ($l) => $l->done_on->year))->min() ?? today()->year;
        $years = range(min($first, today()->year), today()->year);

        $rows = $assets->map(fn (Asset $a) => [
            'asset' => $a,
            'byYear' => collect($years)->mapWithKeys(fn ($y) => [$y => $a->maintenanceCost($y)])->all(),
            'total' => $a->maintenanceCost(),
            'value' => $a->isActive() ? $a->currentValue() : null,
            'gain' => $a->gain(),
            'ownership' => $a->ownershipCost(),
        ]);

        return [
            'years' => $years,
            'rows' => $rows,
            'totals' => collect($years)->mapWithKeys(fn ($y) => [$y => round($rows->sum(fn ($r) => $r['byYear'][$y]), 2)])->all(),
        ];
    }
}
