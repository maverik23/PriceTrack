<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Asset;
use App\Support\AssetData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Crea un bien (auto, refrigerador, laptop…) o, con `id`, cambia uno existente (solo lo que se manda; `null` borra un campo). Mismas reglas que el formulario. Valor estimado: `valuation_method` none (vale la última captura), straight_line (depreciación en línea recta: `useful_life_years` y `salvage_value`) o annual_rate (`annual_rate` % al año, negativo si se deprecia). Venderlo o darlo de baja: `status` sold/retired con `disposed_on` (y `disposal_amount` si se vendió). `meter_unit` si lleva medidor de uso (km, horas).')]
class SaveAsset extends Tool
{
    public function handle(Request $request): Response
    {
        $request->validate(['id' => ['nullable', 'integer']]);
        $asset = $request->get('id') ? $this->find(Asset::class, $request->get('id'), 'un bien') : new Asset;
        $given = collect($request->all())->except('id')->all();

        $input = $asset->exists ? array_merge(AssetData::toInput($asset), $given) : $given + ['status' => 'active', 'valuation_method' => 'none'];
        $data = AssetData::validate($input);

        $creating = ! $asset->exists;
        $asset->fill($data)->save();
        if (! $creating) {
            $asset->syncMaintenanceTasks();
        }

        return $this->json([
            'saved' => $creating ? 'created' : 'updated',
            'asset' => ['id' => $asset->id, 'name' => $asset->name, 'status' => $asset->status, 'current_value' => $asset->currentValue(), 'url' => route('assets.show', $asset)],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Para cambiar uno existente (de list-assets). Sin él se crea.'),
            'name' => $schema->string()->description('Nombre: "Refrigerador LG", "Auto".'),
            'category' => $schema->string()->description('Ej. Electrodomésticos, Vehículos.'),
            'brand' => $schema->string(),
            'model' => $schema->string(),
            'serial_number' => $schema->string(),
            'location' => $schema->string()->description('Dónde está: Cocina, Cochera.'),
            'purchased_on' => $schema->string()->description('Fecha de compra, AAAA-MM-DD.'),
            'purchase_price' => $schema->number()->description('Precio de compra en pesos.'),
            'warranty_until' => $schema->string()->description('Garantía hasta, AAAA-MM-DD.'),
            'notes' => $schema->string(),
            'status' => $schema->string()->enum(array_keys(Asset::STATUSES))->description('active (por defecto), sold o retired.'),
            'disposed_on' => $schema->string()->description('Fecha de venta o baja (obligatoria si no está active).'),
            'disposal_amount' => $schema->number()->description('Lo que se recibió al venderlo.'),
            'valuation_method' => $schema->string()->enum(array_keys(Asset::METHODS))->description('none (por defecto), straight_line o annual_rate.'),
            'useful_life_years' => $schema->number()->description('Vida útil en años (straight_line).'),
            'salvage_value' => $schema->number()->description('Valor al final de la vida útil (straight_line).'),
            'annual_rate' => $schema->number()->description('% al año (annual_rate): -15 se deprecia, 5 plusvalía.'),
            'meter_unit' => $schema->string()->description('Unidad del medidor de uso: km, horas.'),
        ];
    }
}
