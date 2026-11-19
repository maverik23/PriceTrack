<?php

namespace App\Http\Controllers\Api;

use App\Api\ListSheet;
use App\Api\Present;
use App\Models\ShoppingList;
use Illuminate\Http\Request;

/** Listas de compra para la app móvil; la hoja ya calculada sale de App\Api\ListSheet. */
class ShoppingListController extends Controller
{
    private const WITH = ['items.price.provider', 'items.product.prices', 'tickets'];

    /** Por defecto solo las pendientes (`?status=done|all`). */
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['done', 'all'], true) ? $request->query('status') : 'pending';

        $lists = ShoppingList::with(self::WITH)
            ->when($status === 'pending', fn ($q) => $q->pending())
            ->when($status === 'done', fn ($q) => $q->done())
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('notes', 'like', $term));
            })
            ->orderByDesc('shopped_on')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->page($lists, fn (ShoppingList $l) => Present::shoppingList($l), [
            'status' => $status,
            'counts' => [
                'pending' => ShoppingList::pending()->count(),
                'done' => ShoppingList::done()->count(),
                'all' => ShoppingList::count(),
            ],
        ]);
    }

    public function show(ShoppingList $list)
    {
        return response()->json(['data' => ListSheet::data($list)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'shopped_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'copy_from' => ['nullable', 'exists:shopping_lists,id'],
        ], [], ['shopped_on' => 'fecha', 'copy_from' => 'lista a copiar']);

        $list = ShoppingList::create(collect($data)->except('copy_from')->all());

        // Copiar artículos (sin precios) de una lista anterior
        if (! empty($data['copy_from'])) {
            ShoppingList::find($data['copy_from'])->items->each(
                fn ($item) => $list->items()->create(['product_id' => $item->product_id, 'quantity' => $item->quantity])
            );
        }

        return response()->json(['data' => ListSheet::data($list), 'message' => 'Lista creada.'], 201);
    }

    public function update(Request $request, ShoppingList $list)
    {
        $list->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'shopped_on' => ['sometimes', 'required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], ['shopped_on' => 'fecha']));

        return response()->json(['data' => ListSheet::data($list), 'message' => 'Lista actualizada.']);
    }

    public function destroy(ShoppingList $list)
    {
        $list->delete();

        return response()->json(['message' => 'Lista eliminada. Los precios capturados se conservan en el historial.']);
    }

    /** Marca la lista como comprada (`done=1`) o la reabre (`done=0`); sin `done`, la alterna. */
    public function done(Request $request, ShoppingList $list)
    {
        $done = $request->boolean('done', ! $list->isDone());
        $list->markDone($done);

        return response()->json([
            'data' => Present::shoppingList($list->load(self::WITH)),
            'message' => $done ? "«{$list->name}» quedó como comprada." : "«{$list->name}» volvió a pendientes.",
        ]);
    }
}
