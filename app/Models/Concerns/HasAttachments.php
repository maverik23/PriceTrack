<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Fotos y documentos de un modelo; se borran con él (archivos incluidos). */
trait HasAttachments
{
    public static function bootHasAttachments(): void
    {
        static::deleting(function ($model) {
            $model->attachments()->get()->each->delete();
        });
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    /** La primera foto: la portada. */
    public function coverPhoto(): ?Attachment
    {
        return $this->attachments->firstWhere('kind', 'photo');
    }
}
