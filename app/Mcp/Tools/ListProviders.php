<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Provider;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lista las tiendas (proveedores) con su id: Walmart, Costco, Sam\'s… Los precios y las compras se registran por tienda.')]
#[IsReadOnly]
class ListProviders extends Tool
{
    public function handle(Request $request): Response
    {
        return $this->json([
            'providers' => Provider::withCount('prices')->orderBy('name')->get()
                ->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->name, 'notes' => $p->notes, 'prices' => $p->prices_count])->all(),
        ]);
    }
}
