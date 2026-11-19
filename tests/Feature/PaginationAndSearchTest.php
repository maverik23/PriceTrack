<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\RecurringPayment;
use App\Models\ShoppingList;
use App\Models\Task;
use App\Support\Paginate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginationAndSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_assets_can_be_searched_and_paginated_with_the_chosen_page_size(): void
    {
        foreach (range(1, 25) as $n) {
            Asset::create(['name' => sprintf('Bien %02d', $n), 'status' => 'active', 'valuation_method' => 'none']);
        }
        Asset::create(['name' => 'Refrigerador', 'brand' => 'Mabe', 'location' => 'Cocina', 'status' => 'active', 'valuation_method' => 'none']);

        // 20 por página por defecto, con el selector de 10/20/50/100
        $this->get(route('assets.index'))->assertOk()
            ->assertSee('Bien 20')->assertDontSee('Bien 21')
            ->assertSee('1–20 de 26')->assertSee('Por página');

        $this->get(route('assets.index', ['page' => 2]))->assertOk()->assertSee('Bien 21')->assertDontSee('Bien 20');

        // Un tamaño que no está en la lista no se acepta
        $this->get(route('assets.index', ['per_page' => 7]))->assertSee('1–20 de 26');

        // Lo elegido se recuerda en esa pantalla
        $response = $this->get(route('assets.index', ['per_page' => 10]));
        $response->assertOk()->assertSee('Bien 10')->assertDontSee('Bien 11')->assertCookie('per_page_assets_index', '10');
        $this->withCookie('per_page_assets_index', '10')->get(route('assets.index'))->assertSee('1–10 de 26');

        // Busca por nombre, marca o lugar
        $this->get(route('assets.index', ['q' => 'mabe']))->assertOk()->assertSee('Refrigerador')->assertDontSee('Bien 01');
        $this->get(route('assets.index', ['q' => 'cocina']))->assertSee('Refrigerador');
        $this->get(route('assets.index', ['q' => 'nada']))->assertSee('Ningún bien coincide');
    }

    public function test_products_recipes_providers_and_lists_are_paginated_and_searchable(): void
    {
        foreach (range(1, 12) as $n) {
            Product::create(['name' => sprintf('Artículo %02d', $n), 'size' => 1, 'unit' => 'item']);
            Recipe::create(['name' => sprintf('Receta %02d', $n), 'servings' => 2]);
            Provider::create(['name' => sprintf('Tienda %02d', $n)]);
            ShoppingList::create(['name' => sprintf('Lista %02d', $n), 'shopped_on' => today()->subDays($n)]);
        }
        ShoppingList::create(['name' => 'Despensa de diciembre', 'shopped_on' => today()->subMonth()]);

        $this->get(route('products.index', ['per_page' => 10]))->assertOk()->assertSee('Artículo 10')->assertDontSee('Artículo 11');
        $this->get(route('products.index', ['per_page' => 10, 'page' => 2]))->assertSee('Artículo 12');

        $this->get(route('recipes.index', ['per_page' => 10]))->assertOk()->assertSee('Receta 10')->assertDontSee('Receta 11')->assertSee('1–10 de 12');
        $this->get(route('recipes.index', ['per_page' => 10, 'page' => 2]))->assertSee('Receta 12')->assertDontSee('Receta 01');

        $this->get(route('providers.index', ['per_page' => 10]))->assertOk()->assertSee('Tienda 10')->assertDontSee('Tienda 11');
        $this->get(route('providers.index', ['q' => 'tienda 07']))->assertSee('Tienda 07')->assertDontSee('Tienda 08');

        $this->get(route('lists.index', ['q' => 'diciembre']))->assertOk()->assertSee('Despensa de diciembre')->assertDontSee('Lista 01');
        $this->get(route('lists.index', ['per_page' => 10]))->assertSee('1–10 de 13');
    }

    public function test_tasks_can_be_searched_and_the_list_view_paginates_in_group_order(): void
    {
        foreach (range(1, 15) as $n) {
            Task::create(['title' => sprintf('Tarea %02d', $n), 'due_on' => today()->addDays($n), 'priority' => 2]);
        }
        Task::create(['title' => 'Cambiar filtro del agua', 'due_on' => today()->subDay(), 'priority' => 2]);

        $this->get(route('tasks.index', ['view' => 'list', 'per_page' => 10]))->assertOk()
            ->assertSee('Cambiar filtro del agua')->assertSee('Tarea 09')->assertDontSee('Tarea 10')
            ->assertSee('1–10 de 16');
        $this->get(route('tasks.index', ['view' => 'list', 'per_page' => 10, 'page' => 2]))->assertSee('Tarea 15')->assertDontSee('Cambiar filtro');

        $this->get(route('tasks.index', ['view' => 'list', 'q' => 'filtro']))->assertOk()
            ->assertSee('Cambiar filtro del agua')->assertDontSee('Tarea 01');
        $this->get(route('tasks.index', ['q' => 'filtro']))->assertOk()->assertSee('Cambiar filtro del agua');
    }

    public function test_recurring_payments_search_keeps_the_monthly_total_of_all_of_them(): void
    {
        RecurringPayment::create(['name' => 'Luz', 'category' => 'Servicios', 'amount' => 500, 'every' => 1, 'every_unit' => 'month', 'next_due_on' => today()]);
        RecurringPayment::create(['name' => 'Internet', 'category' => 'Servicios', 'amount' => 600, 'every' => 1, 'every_unit' => 'month', 'next_due_on' => today()]);

        $this->get(route('recurring.index', ['q' => 'luz']))->assertOk()
            ->assertSee('Luz')->assertDontSee('href="'.route('recurring.edit', RecurringPayment::where('name', 'Internet')->first()).'"', false)
            ->assertSee('$1,100.00');
        $this->get(route('recurring.index', ['q' => 'agua']))->assertSee('Ningún pago coincide');
    }

    public function test_collection_pagination_clamps_to_the_last_page(): void
    {
        $request = request()->duplicate(['page' => 9]);
        $page = Paginate::collection(collect(range(1, 25)), $request, 10);

        $this->assertSame(3, $page->currentPage());
        $this->assertSame([21, 22, 23, 24, 25], $page->items());
    }
}
