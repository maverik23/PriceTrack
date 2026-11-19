<?php

namespace App\Support;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Fotos y documentos dentro del formulario de alta y edición (recetas, bienes):
 * archivos nuevos (`files[]` + miniaturas `thumbs[i]` hechas en el navegador), los que se
 * quitan (`remove_attachments[]`), títulos (`attachment_titles[id]`) y el orden
 * (`attachment_order[]`: "a{id}" de los guardados, "n{i}" de los nuevos). La primera foto
 * es la portada. Lo arma resources/js/attachments-field.js.
 */
class AttachmentForm
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.Attachment::MAX_KB, 'mimes:'.implode(',', Attachment::MIMES)],
            'thumbs' => ['nullable', 'array'],
            'thumbs.*' => ['nullable', 'file', 'image', 'max:2048'],
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
            'attachment_titles' => ['nullable', 'array'],
            'attachment_titles.*' => ['nullable', 'string', 'max:150'],
            'attachment_order' => ['nullable', 'array'],
            'attachment_order.*' => ['string', 'regex:/^[an]\d+$/'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'files.max' => 'Sube hasta 10 archivos a la vez.',
            'files.*.max' => 'Cada archivo puede pesar hasta '.(Attachment::MAX_KB / 1024).' MB.',
            'files.*.mimes' => 'Solo fotos (jpg, png, webp, heic) y documentos (pdf, Word, Excel, texto).',
        ];
    }

    public static function validate(Request $request): void
    {
        Validator::validate($request->all(), self::rules(), self::messages(), ['files' => 'archivos', 'files.*' => 'archivo', 'attachment_titles.*' => 'título']);
    }

    public static function apply(Model $owner, Request $request): void
    {
        $remove = array_map('intval', $request->input('remove_attachments', []));
        if ($remove) {
            $owner->attachments()->whereIn('id', $remove)->get()->each->delete();
        }

        foreach ($request->input('attachment_titles', []) as $id => $title) {
            $owner->attachments()->whereKey((int) $id)->update(['title' => trim((string) $title) ?: null]);
        }

        $thumbs = $request->file('thumbs', []);
        $tokens = [];
        foreach ($request->file('files', []) as $i => $file) {
            $tokens['n'.$i] = Attachment::store($owner, $file, $thumbs[$i] ?? null)->id;
        }

        $ids = $owner->attachments()->pluck('id')->all();
        foreach ($ids as $id) {
            $tokens['a'.$id] ??= $id;
        }
        // Lo que se ordenó primero; lo que no venía en el orden, al final, como estaba
        $ordered = collect($request->input('attachment_order', []))->map(fn ($token) => $tokens[$token] ?? null)->filter()->unique()->values();
        $ordered = $ordered->merge(array_diff($ids, $ordered->all()))->values();
        foreach ($ordered as $position => $id) {
            $owner->attachments()->whereKey($id)->update(['position' => $position]);
        }
        $owner->unsetRelation('attachments');
    }

    /** Lo que el formulario necesita de lo ya guardado. @return array<int, array<string, mixed>> */
    public static function existing(?Model $owner): array
    {
        if (! $owner?->exists) {
            return [];
        }

        return $owner->attachments()->get()->map(fn (Attachment $a) => [
            'key' => 'a'.$a->id,
            'id' => $a->id,
            'photo' => $a->isPhoto(),
            'viewable' => $a->isViewable(),
            'thumb' => $a->url(true),
            'url' => $a->url(),
            'name' => $a->original_name,
            'title' => $a->title,
            'size' => $a->sizeLabel(),
        ])->all();
    }
}
