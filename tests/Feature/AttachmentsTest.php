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
}
