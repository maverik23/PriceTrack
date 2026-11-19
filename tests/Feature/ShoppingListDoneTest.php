<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ShoppingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingListDoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_pending_lists_by_default_and_done_ones_struck_through(): void
    {
        $pending = ShoppingList::create(['name' => 'Semana nueva', 'shopped_on' => today()]);
        $done = ShoppingList::create(['name' => 'Semana pasada', 'shopped_on' => today()->subWeek(), 'completed_at' => now()]);

        $this->get(route('lists.index'))->assertOk()
            ->assertSee('Semana nueva')->assertDontSee('Semana pasada');

        $this->get(route('lists.index', ['status' => 'done']))->assertOk()
            ->assertSee('Semana pasada')->assertDontSee('Semana nueva')->assertSee('is-done', false);

        $this->get(route('lists.index', ['status' => 'all']))->assertOk()
            ->assertSee('Semana pasada')->assertSee('Semana nueva');

        $this->post(route('lists.done', $pending), ['done' => 1])->assertRedirect();
        $this->assertTrue($pending->fresh()->isDone());

        $this->post(route('lists.done', $done), ['done' => 0])->assertRedirect();
        $this->assertFalse($done->fresh()->isDone());
    }

    public function test_buying_the_last_item_marks_the_list_done_and_a_new_pending_item_reopens_it(): void
    {
        $provider = Provider::create(['name' => 'Walmart']);
        $milk = Product::create(['name' => 'Leche', 'size' => 1, 'unit' => 'l']);
        $eggs = Product::create(['name' => 'Huevo', 'size' => 30, 'unit' => 'item']);
        $list = ShoppingList::create(['name' => 'Súper', 'shopped_on' => today()]);
        $a = $list->items()->create(['product_id' => $milk->id, 'quantity' => 1]);
        $b = $list->items()->create(['product_id' => $eggs->id, 'quantity' => 1]);

        $milkPrice = Price::create(['product_id' => $milk->id, 'provider_id' => $provider->id, 'price' => 28, 'pack_qty' => 1, 'size' => 1, 'unit' => 'l', 'observed_on' => today()]);
        $eggPrice = Price::create(['product_id' => $eggs->id, 'provider_id' => $provider->id, 'price' => 90, 'pack_qty' => 1, 'size' => 30, 'unit' => 'item', 'observed_on' => today()]);

        $a->update(['price_id' => $milkPrice->id]);
        $this->assertFalse($list->fresh()->isDone());

        $b->update(['price_id' => $eggPrice->id]);
        $this->assertTrue($list->fresh()->isDone(), 'Con todo comprado la lista queda comprada');

        $b->update(['price_id' => null]);
        $this->assertFalse($list->fresh()->isDone(), 'Desmarcar algo la regresa a pendientes');
    }
}
