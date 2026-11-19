<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['asset_id', 'maintenance_plan_id', 'done_on', 'meter_reading', 'cost', 'provider', 'notes', 'task_id'])]
class MaintenanceLog extends Model
{
    protected function casts(): array
    {
        return [
            'done_on' => 'date',
            'meter_reading' => 'float',
            'cost' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // Cada servicio mueve la fecha del próximo
        $sync = fn (MaintenanceLog $log) => $log->plan?->syncTask();
        static::saved($sync);
        static::deleted($sync);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }
}
