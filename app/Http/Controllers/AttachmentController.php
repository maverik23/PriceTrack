<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Fotos y documentos de bienes y recetas. Se sirven desde el disco privado. */
class AttachmentController extends Controller
{
    public function storeForAsset(Request $request, Asset $asset)
    {
        return $this->store($request, $asset, route('assets.show', $asset));
    }

    public function storeForRecipe(Request $request, Recipe $recipe)
    {
        return $this->store($request, $recipe, route('recipes.show', $recipe));
    }

    /** El archivo en el navegador (?thumb=1 la miniatura, ?download=1 para descargar). */
    public function show(Request $request, Attachment $attachment)
    {
        $path = $request->boolean('thumb') && $attachment->thumb_path ? $attachment->thumb_path : $attachment->path;
        abort_unless(Storage::disk(Attachment::DISK)->exists($path), 404);

        $headers = ['Cache-Control' => 'private, max-age=604800'];
        if ($request->boolean('download')) {
            return Storage::disk(Attachment::DISK)->download($path, $attachment->original_name, $headers);
        }

        return Storage::disk(Attachment::DISK)->response($path, $attachment->original_name, $headers + [
            'Content-Type' => $path === $attachment->thumb_path ? 'image/jpeg' : $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, Attachment $attachment)
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:150']], [], ['title' => 'título']);
        $attachment->update(['title' => $data['title'] ?: null]);

        return back()->with('success', 'Archivo actualizado.');
    }

    public function destroy(Request $request, Attachment $attachment)
    {
        $attachment->delete();

        if ($request->expectsJson()) {
            return response()->json(['message' => "«{$attachment->label()}» eliminado."]);
        }

        return back()->with('success', "«{$attachment->label()}» eliminado.");
    }

    private function store(Request $request, Model $owner, string $redirect)
    {
        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.Attachment::MAX_KB, 'mimes:'.implode(',', Attachment::MIMES)],
            'thumbs' => ['nullable', 'array'],
            'thumbs.*' => ['nullable', 'file', 'image', 'max:2048'],
            'title' => ['nullable', 'string', 'max:150'],
        ], [
            'files.required' => 'Elige al menos un archivo.',
            'files.*.max' => 'Cada archivo puede pesar hasta '.(Attachment::MAX_KB / 1024).' MB.',
            'files.*.mimes' => 'Solo fotos (jpg, png, webp, heic) y documentos (pdf, Word, Excel, texto).',
        ], ['files' => 'archivos', 'files.*' => 'archivo', 'title' => 'título']);

        $thumbs = $request->file('thumbs', []);
        $files = $request->file('files');
        foreach ($files as $i => $file) {
            Attachment::store($owner, $file, $thumbs[$i] ?? null, count($files) === 1 ? $request->input('title') : null);
        }

        return redirect($redirect)->with('success', count($files) === 1 ? 'Archivo guardado.' : count($files).' archivos guardados.');
    }
}
