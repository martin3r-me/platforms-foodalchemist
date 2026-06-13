<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;

/**
 * M10R-3 / Doc 15 §10.8 („Generelle Bewertung", ENTSCHIEDEN): DETERMINISTISCHE
 * Menü-Checks jetzt — Gang-/Struktur-Abdeckung · Niveau-Konsistenz · Diät-Abdeckung ·
 * Preis im Zielkorridor · Allergen-Konfidenz. Ergebnis = Score + Checkliste fürs
 * Detail-Panel und den Editor. Das LLM-Kohärenz-Urteil (passen die Gänge zusammen?)
 * ist eine spätere Ausbaustufe — hier KEINE KI, nur Regeln.
 *
 * Pure: nimmt die schon berechneten cockpit/aggregat-Daten als Eingabe (keine
 * doppelte Aggregation), liest nur die Paket-Niveaus gezielt nach.
 */
class ConcepterBewertungService
{
    /** Korridor-Toleranzen um den Zielpreis (€/Person). */
    private const PREIS_OK = 0.10;    // ±10 % = ok

    private const PREIS_WARN = 0.20;  // ±20 % = warn, darüber fail

    /**
     * @param  array  $cockpit   ConceptService::preisCockpit()
     * @param  array  $aggregat  ConcepterAggregateService::conceptAggregat()
     * @return array{score:int, checks: list<array{key:string, label:string, status:string, detail:string}>}
     */
    public function bewerten(FoodAlchemistConcept $concept, array $cockpit, array $aggregat): array
    {
        $checks = [];

        // 1 — Struktur / Gang-Abdeckung
        $nSlots = count($cockpit['zeilen'] ?? []);
        if ($nSlots === 0) {
            $checks[] = $this->check('struktur', 'Struktur', 'fail', 'Noch keine Positionen angelegt.');
        } elseif (! empty($cockpit['hat_leer'])) {
            $checks[] = $this->check('struktur', 'Struktur', 'warn', 'Es gibt leere Positionen — Paket oder Gericht setzen.');
        } else {
            $checks[] = $this->check('struktur', 'Struktur', 'ok', $nSlots . ' Positionen, alle befüllt.');
        }

        // 2 — Niveau-Konsistenz über die Gänge
        $paketNiveaus = DB::table('foodalchemist_concept_slots as s')
            ->join('foodalchemist_pakete as p', 'p.id', '=', 's.paket_id')
            ->where('s.concept_id', $concept->id)->whereNull('s.deleted_at')
            ->whereNotNull('p.niveau')->distinct()->pluck('p.niveau')->all();
        if (count($paketNiveaus) === 0) {
            $checks[] = $this->check('niveau', 'Niveau-Konsistenz', 'info', 'Keine Niveau-Angabe an den Paketen.');
        } elseif (count($paketNiveaus) > 1) {
            $checks[] = $this->check('niveau', 'Niveau-Konsistenz', 'warn', 'Gemischte Niveaus: ' . implode(', ', $paketNiveaus) . '.');
        } elseif ($concept->niveau && $paketNiveaus[0] !== $concept->niveau) {
            $checks[] = $this->check('niveau', 'Niveau-Konsistenz', 'warn', 'Pakete „' . $paketNiveaus[0] . '" ≠ Concept-Niveau „' . $concept->niveau . '".');
        } else {
            $checks[] = $this->check('niveau', 'Niveau-Konsistenz', 'ok', 'Einheitliches Niveau (' . $paketNiveaus[0] . ').');
        }

        // 3 — Diät-Abdeckung (gegen die Vorgabe, sonst Info aus dem Rollup)
        $a = $aggregat['allergene'] ?? [];
        $vorgabe = mb_strtolower((string) ($concept->diaet_vorgabe ?? ''));
        if ($vorgabe !== '' && str_contains($vorgabe, 'vegan')) {
            $checks[] = $this->check('diaet', 'Diät-Vorgabe (vegan)', ! empty($a['is_vegan']) ? 'ok' : 'fail',
                ! empty($a['is_vegan']) ? 'Menü ist durchgängig vegan.' : 'Vorgabe vegan, aber nicht alle Gänge sind vegan.');
        } elseif ($vorgabe !== '' && (str_contains($vorgabe, 'veget') || str_contains($vorgabe, 'veg'))) {
            $checks[] = $this->check('diaet', 'Diät-Vorgabe (vegetarisch)', ! empty($a['is_vegetarian']) ? 'ok' : 'fail',
                ! empty($a['is_vegetarian']) ? 'Menü ist durchgängig vegetarisch.' : 'Vorgabe vegetarisch, aber nicht alle Gänge sind vegetarisch.');
        } else {
            $label = ! empty($a['is_vegan']) ? 'durchgängig vegan' : (! empty($a['is_vegetarian']) ? 'durchgängig vegetarisch' : 'gemischt (enthält Fleisch/Fisch)');
            $checks[] = $this->check('diaet', 'Diät-Profil', 'info', 'Keine Vorgabe — Menü ist ' . $label . '.');
        }

        // 4 — Preis im Zielkorridor
        $ziel = $concept->zielpreis_pro_person !== null ? (float) $concept->zielpreis_pro_person : null;
        $ist = (float) ($cockpit['preis_pro_person'] ?? 0);
        if ($ziel === null || $ziel <= 0) {
            $checks[] = $this->check('preis', 'Preis-Korridor', 'info', 'Kein Zielpreis gesetzt.');
        } else {
            $abw = abs($ist - $ziel) / $ziel;
            $status = $abw <= self::PREIS_OK ? 'ok' : ($abw <= self::PREIS_WARN ? 'warn' : 'fail');
            $checks[] = $this->check('preis', 'Preis-Korridor', $status,
                'Ist ' . number_format($ist, 2, ',', '.') . ' € vs. Ziel ' . number_format($ziel, 2, ',', '.') . ' € (' . round($abw * 100) . ' % Abw.).');
        }

        // 5 — Allergen-Konfidenz
        $konf = $a['konfidenz'] ?? 'unknown';
        $status = ['high' => 'ok', 'medium' => 'warn', 'low' => 'fail', 'unknown' => 'fail'][$konf] ?? 'fail';
        $checks[] = $this->check('allergen', 'Allergen-Konfidenz', $status, 'Schwächstes Gericht: ' . $konf . '.');

        // Score = Anteil „ok" an den anwendbaren Checks (info zählt nicht).
        $anwendbar = array_filter($checks, fn ($c) => $c['status'] !== 'info');
        $ok = array_filter($anwendbar, fn ($c) => $c['status'] === 'ok');
        $score = count($anwendbar) > 0 ? (int) round(count($ok) / count($anwendbar) * 100) : 0;

        return ['score' => $score, 'checks' => $checks];
    }

    private function check(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
