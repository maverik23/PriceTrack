<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Product;
use App\Models\ProductLink;
use Illuminate\Support\Str;
use Throwable;

/**
 * Foto del artículo desde internet: de su página en alguna tienda (las direcciones de
 * "En cada tienda") o, si no, de Open Food Facts por su código de barras. Se guarda como
 * la primera foto (la portada) del artículo.
 */
class ProductPhoto
{
    public function __construct(private PriceFetcher $fetcher, private BarcodeLookup $lookup) {}

    /**
     * Busca y guarda la foto. Con `$link` solo en esa tienda.
     *
     * @return array{ok: bool, message: string, attachment: ?Attachment}
     */
    public function fetch(Product $product, ?ProductLink $link = null): array
    {
        $links = $link ? collect([$link]) : $product->links()->whereNotNull('url')->with('provider')->get();
        $problems = [];

        foreach ($links as $candidate) {
            try {
                $url = $this->fetcher->image($candidate->url);
                if ($url) {
                    return $this->save($product, $url, $candidate->provider?->name ?? parse_url($candidate->url, PHP_URL_HOST));
                }
                $problems[] = ($candidate->provider?->name ?? 'La tienda').': sin foto en la página';
            } catch (PriceFetchException $e) {
                $problems[] = ($candidate->provider?->name ?? 'La tienda').': '.$e->getMessage();
            }
        }

        if (! $link && $product->barcode) {
            try {
                $found = $this->lookup->find($product->barcode);
                if ($found['image'] ?? null) {
                    return $this->save($product, self::larger($found['image']), $found['source'] ?? 'internet');
                }
                $problems[] = 'Open Food Facts: sin foto';
            } catch (Throwable $e) {
                $problems[] = 'Código de barras: '.$e->getMessage();
            }
        }

        $message = match (true) {
            $problems !== [] => 'No encontré foto. '.implode('; ', $problems).'.',
            default => 'No encontré foto: pega la dirección del artículo en alguna tienda («En cada tienda») o ponle su código de barras.',
        };

        return ['ok' => false, 'message' => $message, 'attachment' => null];
    }

    /** Si el artículo no tiene foto, guarda la que dio la tienda al consultar el precio (sin fallar nunca). */
    public function saveIfMissing(Product $product, ?string $imageUrl, string $source): void
    {
        if (! $imageUrl || $product->attachments()->where('kind', 'photo')->exists()) {
            return;
        }
        try {
            $this->save($product, $imageUrl, $source);
        } catch (Throwable) {
            // Sin foto no pasa nada: el precio ya se guardó
        }
    }

    /**
     * Descarga y guarda la foto como portada.
     *
     * @return array{ok: bool, message: string, attachment: Attachment}
     *
     * @throws PriceFetchException
     */
    public function save(Product $product, string $url, string $source): array
    {
        $image = $this->fetcher->download($url);
        $name = Str::slug(Str::limit($product->name, 60, '')) ?: 'foto';
        $attachment = Attachment::storeContents($product, $image['contents'], $image['mime'], $image['extension'], "{$name}.{$image['extension']}", "Foto de {$source}", first: true);
        $product->unsetRelation('attachments');

        return ['ok' => true, 'message' => "Foto de {$source} guardada.", 'attachment' => $attachment];
    }

    /** Open Food Facts da la foto chica (…/front_es.3.200.jpg): se pide la de 400 px. */
    public static function larger(string $url): string
    {
        return preg_replace('#\.(100|200)\.jpg$#', '.400.jpg', $url);
    }
}
