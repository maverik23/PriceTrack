<?php

namespace App\Http\Controllers;

use App\Models\ShoppingList;
use App\Support\ListGrid;
use Illuminate\Http\Request;

class ShoppingListController extends Controller
{
    /** Por defecto solo las pendientes; las compradas se ven tachadas con ?status=done o all. */
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['done', 'all'], true) ? $request->query('status') : 'pending';

        $lists = ShoppingList::with(['items.price.provider', 'items.product.prices', 'tickets'])
            ->when($status === 'pending', fn ($q) => $q->pending())
            ->when($status === 'done', fn ($q) => $q->done())
            ->orderByDesc('shopped_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('lists.index', [
            'lists' => $lists,
            'status' => $status,
            'counts' => [
                'pending' => ShoppingList::pending()->count(),
                'done' => ShoppingList::done()->count(),
                'all' => ShoppingList::count(),
            ],
        ]);
    }

    /**
     * Qué tiendas se ven en la hoja: por defecto solo las que tienen datos en la lista;
     * `added` = mostradas a mano, `hidden` = ocultadas a mano.
     */
    public function providerColumns(Request $request, ShoppingList $list)
    {
        $data = $request->validate([
            'added' => ['present', 'array'],
            'added.*' => ['integer', 'exists:providers,id'],
            'hidden' => ['present', 'array'],
            'hidden.*' => ['integer', 'exists:providers,id'],
        ]);
        $added = array_values(array_unique(array_map('intval', $data['added'])));
        $hidden = array_values(array_diff(array_unique(array_map('intval', $data['hidden'])), $added));
        $list->update(['provider_columns' => ['added' => $added, 'hidden' => $hidden]]);

        return response()->json(['provider_columns' => $list->provider_columns]);
    }

    /** Marca la lista como comprada o la reabre. */
    public function toggleDone(Request $request, ShoppingList $list)
    {
        $done = $request->boolean('done', ! $list->isDone());
        $list->markDone($done);

        return back()->with('success', $done ? "«{$list->name}» quedó como comprada." : "«{$list->name}» volvió a pendientes.");
    }

    public function create()
    {
        return view('lists.create', [
            'list' => new ShoppingList(['name' => 'Despensa '.today()->translatedFormat('d M Y'), 'shopped_on' => today()]),
            'previous' => ShoppingList::withCount('items')->orderByDesc('shopped_on')->orderByDesc('id')->take(20)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'shopped_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'copy_from' => ['nullable', 'exists:shopping_lists,id'],
        ]);

        $list = ShoppingList::create($data);

        // Copiar artículos (sin precios) de una lista anterior
        if ($request->filled('copy_from')) {
            ShoppingList::find($request->integer('copy_from'))->items->each(
                fn ($item) => $list->items()->create(['product_id' => $item->product_id, 'quantity' => $item->quantity])
            );
        }

        return redirect()->route('lists.show', $list)->with('success', 'Lista creada.');
    }

    public function show(ShoppingList $list)
    {
        $list->load([
            'items' => fn ($q) => $q->orderBy('id'),
            'items.product.prices',
            'items.product.links',
            'items.price',
        ]);

        // Totales, más barato y comparación por tienda se calculan en vivo en la
        // tabla (resources/js/list-grid.js) con los mismos criterios que los modelos.
        return view('lists.show', [
            'list' => $list,
            'grid' => ListGrid::page($list),
        ]);
    }

    public function edit(ShoppingList $list)
    {
        return view('lists.edit', compact('list'));
    }

    public function update(Request $request, ShoppingList $list)
    {
        $list->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'shopped_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]));

        return redirect()->route('lists.show', $list)->with('success', 'Lista actualizada.');
    }

    public function destroy(ShoppingList $list)
    {
        $list->delete();

        return redirect()->route('lists.index')->with('success', 'Lista eliminada. Los precios capturados se conservan en el historial.');
    }
}
