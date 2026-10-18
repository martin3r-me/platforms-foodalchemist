<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Rezepte nachrechnen, deren Allergene/Zusatzstoffe an geänderten Artikeln hängen (Kurator 18.10.: veraltete Rezept-Allergene nach einer
 * Necta-Änderung sind ein Sicherheitsthema). EIN Weg für den nächtlichen/wöchentlichen Lauf und die Prüffall-Entscheidung.
 *
 * Artikel → GPs (supplier_item_structures) + deren Derivate (erben LIVE) → Rezepte inkl. Eltern und Fassungen → recomputeMany:
 * dedupliziert, Kinder vor Eltern, jedes Rezept in der Sicht SEINES Teams (Pipeline lesenIm). Scheitern Rezepte, bleibt der Aufrufer
 * erfolgreich (die Artikel-Daten stimmen), aber der Master bekommt ein kritisches Signal mit den Rezept-IDs und dem ersten Fehler.
 */
class NectaRezeptNachrechnen
{
    /**
     * @param  list<int>  $artikelIds
     * @return list<int>
     */
    public function rezepteFuer(array $artikelIds): array
    {
        if ($artikelIds === []) {
            return [];
        }
        $gpIds = [];
        foreach (array_chunk(array_values(array_unique($artikelIds)), 1000) as $teil) {
            $gpIds = array_merge($gpIds, DB::table('foodalchemist_supplier_item_structures')->whereIn('supplier_item_id', $teil)
                ->whereNull('deleted_at')->whereNotNull('gp_id')->pluck('gp_id')->map(fn ($v) => (int) $v)->all());
        }
        $gpIds = array_values(array_unique($gpIds));
        if ($gpIds !== []) {
            $gpIds = array_values(array_unique(array_merge($gpIds, DB::table('foodalchemist_gps')->whereIn('derivat_von_gp_id', $gpIds)
                ->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all())));
        }

        return app(KostenCacheService::class)->rezepteMitGps($gpIds);
    }

    /**
     * @param  list<int>  $artikelIds
     * @param  string  $anlass  steht im Signal-Titel, z. B. „Necta-Lauf #19“ oder „Necta-Prüffall-Entscheidung“
     * @return array{rezepte:int, nachgerechnet:int, fehler:array<int,string>}
     */
    public function nachrechnen(array $artikelIds, string $anlass, string $dedupKey, ?int $laufId = null, bool $probe = false): array
    {
        $rezepte = $this->rezepteFuer($artikelIds);
        $raus = ['rezepte' => count($rezepte), 'nachgerechnet' => 0, 'fehler' => []];
        if ($rezepte === []) {
            return $raus;
        }
        if ($probe) {
            $raus['nachgerechnet'] = count($rezepte);   // Probe: so viele würden nachgerechnet

            return $raus;
        }
        $fehler = [];
        try {
            $betroffen = app(RecipeRecomputeService::class)->recomputeMany($rezepte, $fehler);
            $raus['nachgerechnet'] = count($betroffen) - count($fehler);
        } catch (\Throwable $e) {
            // vor der Rezept-Schleife gescheitert (z. B. Eltern-Suche): dann gilt keins als nachgerechnet
            foreach ($rezepte as $rid) {
                $fehler[(int) $rid] ??= $e->getMessage();
            }
            Log::warning('Necta: Rezepte nachrechnen fehlgeschlagen — '.$e->getMessage());
        }
        $raus['fehler'] = $fehler;
        if ($fehler !== []) {
            $this->signal($fehler, $anlass, $dedupKey, $laufId);
        }

        return $raus;
    }

    /** @param array<int, string> $fehler recipe_id => Fehlermeldung */
    private function signal(array $fehler, string $anlass, string $dedupKey, ?int $laufId): void
    {
        $master = MasterTeam::id() !== null ? Team::find(MasterTeam::id()) : null;
        if ($master === null) {
            return;
        }
        $erster = (string) reset($fehler);
        $ids = array_keys($fehler);
        app(SignalService::class)->erzeuge($master, SignalTyp::NectaRezepteNachrechnenFehler, SignalSeverity::Kritisch,
            sprintf('%s: %d Rezepte konnten nach Allergen-Änderung nicht nachgerechnet werden', $anlass, count($ids)), [
                'dedup_key' => $dedupKey, 'source' => 'necta_abgleich', 'ref_type' => $laufId !== null ? 'necta_lauf' : null, 'ref_id' => $laufId,
                'description' => mb_substr('Rezepte '.implode(', ', array_slice($ids, 0, 50)).(count($ids) > 50 ? ' …' : '').' — erster Fehler: '.$erster, 0, 1000),
                'payload' => ['lauf_id' => $laufId, 'anzahl' => count($ids), 'rezept_ids' => $ids, 'erster_fehler' => mb_substr($erster, 0, 500)],
            ]);
    }
}
