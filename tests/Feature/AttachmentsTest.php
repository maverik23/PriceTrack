<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
    }

    public function test_assets_keep_photos_and_documents_privately(): void
    {
        $asset = Asset::create(['name' => 'Refri']);
        $this->post(route('assets.attachments.store', $asset), [
            'files' => [UploadedFile::fake()->image('refri.jpg', 1200, 900), UploadedFile::fake()->create('factura.pdf', 300, 'application/pdf')],
            'thumbs' => [UploadedFile::fake()->image('thumb.jpg', 480, 360)],
        ])->assertRedirect(route('assets.show', $asset))->assertSessionHasNoErrors();

        [$photo, $pdf] = $asset->attachments()->get()->all();
        $this->assertSame('photo', $photo->kind);
        $this->assertNotNull($photo->thumb_path);
        $this->assertSame('document', $pdf->kind);
        $this->assertNull($pdf->thumb_path, 'los documentos no llevan miniatura');
        $this->assertSame('factura.pdf', $pdf->original_name);
        Storage::disk(Attachment::DISK)->assertExists([$photo->path, $photo->thumb_path, $pdf->path]);
        $this->assertStringStartsWith('attachments/', $photo->path);

        $this->get($photo->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get($photo->url(true))->assertOk();
        $this->get(route('attachments.show', [$pdf, 'download' => 1]))->assertOk()->assertDownload('factura.pdf');
        $this->get(route('assets.show', $asset))->assertOk()->assertSee('factura.pdf')->assertSee('Portada');
        $this->get(route('assets.index'))->assertOk()->assertSee($photo->url(true), false);

        $this->patch(route('attachments.update', $pdf), ['title' => 'Factura de compra'])->assertRedirect();
        $this->assertSame('Factura de compra', $pdf->fresh()->label());

        $this->delete(route('attachments.destroy', $pdf))->assertRedirect();
        Storage::disk(Attachment::DISK)->assertMissing($pdf->path);

        // Borrar el bien borra sus archivos
        $asset->delete();
        $this->assertSame(0, Attachment::count());
        Storage::disk(Attachment::DISK)->assertMissing([$photo->path, $photo->thumb_path]);
    }

    public function test_recipes_get_a_cover_photo(): void
    {
        $recipe = Recipe::create(['name' => 'Pozole', 'servings' => 6]);
        $this->post(route('recipes.attachments.store', $recipe), [
            'files' => [UploadedFile::fake()->image('pozole.png')], 'title' => 'Así queda',
        ])->assertSessionHasNoErrors();

        $cover = $recipe->load('attachments')->coverPhoto();
        $this->assertSame('Así queda', $cover->title);
        $this->assertNull($cover->thumb_path, 'sin miniatura del navegador se usa la original');
        $this->assertSame($cover->url(), $cover->url(true));
        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee($cover->url(), false);
        $this->get(route('recipes.index'))->assertOk()->assertSee($cover->url(), false);
    }

    public function test_validation(): void
    {
        $asset = Asset::create(['name' => 'Auto']);
        $this->post(route('assets.attachments.store', $asset), [])->assertSessionHasErrors('files');
        $this->post(route('assets.attachments.store', $asset), ['files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')]])
            ->assertSessionHasErrors('files.0');
        $this->post(route('assets.attachments.store', $asset), ['files' => [UploadedFile::fake()->create('enorme.pdf', Attachment::MAX_KB + 1, 'application/pdf')]])
            ->assertSessionHasErrors('files.0');
        $this->assertSame(0, Attachment::count());

        $missing = $asset->attachments()->create(['kind' => 'document', 'path' => 'attachments/no-existe.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]);
        $this->get($missing->url())->assertNotFound();
    }

    public function test_photos_are_uploaded_ordered_and_removed_from_the_edit_form(): void
    {
        $this->post(route('recipes.store'), [
            'name' => 'Pozole', 'servings' => 6,
            'files' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->create('nota.pdf', 20, 'application/pdf')],
            'thumbs' => [1 => UploadedFile::fake()->image('b-thumb.jpg', 480, 320)],
            // La segunda foto como portada
            'attachment_order' => ['n1', 'n0', 'n2'],
        ])->assertSessionHasNoErrors();
        $recipe = Recipe::latest('id')->firstOrFail();
        $this->assertSame(['b.jpg', 'a.jpg', 'nota.pdf'], $recipe->attachments()->pluck('original_name')->all());
        $this->assertSame('b.jpg', $recipe->load('attachments')->coverPhoto()->original_name);
        $this->assertNotNull($recipe->coverPhoto()->thumb_path);
        [$b, $a, $pdf] = $recipe->attachments()->get()->all();

        // Editar: otra portada, título al documento, quitar una foto y agregar otra
        $this->put(route('recipes.update', $recipe), [
            'name' => 'Pozole', 'servings' => 6,
            'files' => [UploadedFile::fake()->image('c.jpg')],
            'attachment_order' => ['a'.$a->id, 'n0', 'a'.$pdf->id],
            'remove_attachments' => [$b->id],
            'attachment_titles' => [$pdf->id => 'Receta de la abuela', $a->id => ''],
        ])->assertSessionHasNoErrors()->assertRedirect(route('recipes.show', $recipe));
        $this->assertSame(['a.jpg', 'c.jpg', 'nota.pdf'], $recipe->attachments()->pluck('original_name')->all());
        $this->assertSame('Receta de la abuela', $pdf->fresh()->title);
        Storage::disk(Attachment::DISK)->assertMissing($b->path);

        // Sin tocar las fotos, se quedan como estaban
        $this->put(route('recipes.update', $recipe), ['name' => 'Pozole rojo', 'servings' => 6])->assertSessionHasNoErrors();
        $this->assertSame(3, $recipe->attachments()->count());

        // La ficha solo muestra la galería; se edita en el formulario
        $this->get(route('recipes.edit', $recipe))->assertOk()->assertSee('Agregar fotos o documentos');
        $this->get(route('recipes.show', $recipe))->assertOk()->assertSee('Receta de la abuela')->assertDontSee('Agregar fotos o documentos');

        // Archivos no permitidos: error y no se guarda nada
        $this->put(route('recipes.update', $recipe), ['name' => 'Pozole', 'servings' => 6, 'files' => [UploadedFile::fake()->create('x.exe', 5, 'application/octet-stream')]])
            ->assertSessionHasErrors('files.0');
        $this->assertSame('Pozole rojo', $recipe->fresh()->name);
    }

    public function test_assets_take_photos_in_their_form(): void
    {
        $this->post(route('assets.store'), [
            'name' => 'Lavadora', 'status' => 'active', 'valuation_method' => 'none',
            'files' => [UploadedFile::fake()->image('lavadora.jpg'), UploadedFile::fake()->create('garantia.pdf', 50, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $asset = Asset::latest('id')->firstOrFail();
        $this->assertSame(2, $asset->attachments()->count());
        $this->get(route('assets.show', $asset))->assertOk()->assertSee('garantia.pdf')->assertSee('Portada');
        $this->get(route('assets.edit', $asset))->assertOk()->assertSee('lavadora.jpg');
    }
}
