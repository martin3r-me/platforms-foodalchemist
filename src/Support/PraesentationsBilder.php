<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Model;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;

/**
 * Bild-Identifier für den Präsentations-Snapshot eines Angebots (aus PresentationService ausgelagert,
 * Dateigrößen-Wächter): Gericht-Fotos der Komposition und freie Präsentationsbilder 1–3.
 * Liefert nur Identifier ({context_file_id, path}); URLs löst PresentationService::hydrateImages auf.
 */
final class PraesentationsBilder
{
    /**
     * Gericht-Fotos aller Gerichte einer Angebots-Komposition in EINER Abfrage (kein N+1).
     *
     * @return array<int, array{context_file_id:?int, path:?string}>
     */
    public static function gerichtFotos(array $komp): array
    {
        $ids = [];
        foreach ($komp['kapitel'] as $k) {
            foreach (array_merge($k['bloecke'] ?? [], $k['editionen'] ?? []) as $b) {
                foreach ($b['gerichte'] ?? [] as $g) {
                    if (! empty($g['recipe_id'])) {
                        $ids[(int) $g['recipe_id']] = true;
                    }
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        $fotos = [];
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistRecipe::whereIn('id', array_keys($ids))
            ->where(fn ($q) => $q->whereNotNull('image_context_file_id')->orWhereNotNull('image_path'))
            ->get(['id', 'image_context_file_id', 'image_path']) as $r) {
            $fotos[(int) $r->id] = ['context_file_id' => $r->image_context_file_id, 'path' => $r->image_path];
        }

        return $fotos;
    }

    /** @return array<string, array{context_file_id:?int, path:?string}> Nummer ("1".."3") → Identifier */
    public static function angebotsBilder(Model $e): array
    {
        $roh = $e instanceof FoodAlchemistAngebot && is_array($e->presentation_images_json) ? $e->presentation_images_json : [];
        $out = [];
        foreach (['1', '2', '3'] as $n) {
            $b = $roh[$n] ?? null;
            if (is_array($b) && (($b['context_file_id'] ?? null) || ($b['path'] ?? null))) {
                $out[$n] = ['context_file_id' => $b['context_file_id'] ?? null, 'path' => $b['path'] ?? null];
            }
        }

        return $out;
    }
}
