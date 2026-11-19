<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['kind', 'position', 'title', 'path', 'thumb_path', 'original_name', 'mime', 'size'])]
class Attachment extends Model
{
    public const DISK = 'local';

    /** Extensiones aceptadas (fotos y documentos). */
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'csv'];

    /** Tamaño máximo por archivo, en KB (también en docker/php.ini). */
    public const MAX_KB = 20480;

    protected function casts(): array
    {
        return ['size' => 'integer', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleted(function (Attachment $attachment) {
            Storage::disk(self::DISK)->delete(array_filter([$attachment->path, $attachment->thumb_path]));
        });
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Guarda el archivo (y su miniatura, si llegó) y crea el registro. */
    public static function store(Model $owner, UploadedFile $file, ?UploadedFile $thumb = null, ?string $title = null): self
    {
        $folder = 'attachments/'.now()->format('Y/m');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $name = Str::uuid();
        $path = $file->storeAs($folder, "{$name}.{$ext}", self::DISK);
        $isPhoto = str_starts_with((string) $file->getMimeType(), 'image/');
        $thumbPath = $isPhoto && $thumb ? $thumb->storeAs($folder, "{$name}-thumb.jpg", self::DISK) : null;

        return $owner->attachments()->create([
            'kind' => $isPhoto ? 'photo' : 'document',
            // Al final de la galería
            'position' => (int) $owner->attachments()->max('position') + 1,
            'title' => $title ?: null,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ]);
    }

    public function isPhoto(): bool
    {
        return $this->kind === 'photo';
    }

    public function label(): string
    {
        return $this->title ?: $this->original_name;
    }

    /** "1.2 MB", "340 KB". */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1).' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }

    public function url(bool $thumb = false): string
    {
        return route('attachments.show', [$this, 'thumb' => $thumb && $this->thumb_path ? 1 : null]);
    }

    /** Los navegadores (menos Safari) no muestran HEIC: se ofrece descargar. */
    public function isViewable(): bool
    {
        return $this->isPhoto() ? ! in_array($this->mime, ['image/heic', 'image/heif'], true) || $this->thumb_path : $this->mime === 'application/pdf';
    }
}
