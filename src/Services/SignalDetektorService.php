<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * #378 — Detektor für Klasse-B-Signale. Idempotent über dedup_key (SignalService).
 * Aktiv: Datenqualität GP/LA. Skelett (TODO): veraltete Preise, Preis-Anomalie (#375),
 * Marge unter Ziel. Lauf später via Scheduler (Command folgt).
 */
class SignalDetektorService
{
    public function __construct(private SignalService $signals)
    {
    }

    /** Alle Detektoren; Rückgabe = Anzahl erzeugter/aktualisierter Signale. */
    public function laufen(Team $team): int
    {
        return $this->datenqualitaetGpLa($team)
            + $this->veraltetePreise($team)
            + $this->preisAnomalie($team)
            + $this->margeUnterZiel($team)
            + $this->wareneinsatzUeberZiel($team)
            + $this->naehrwertPlausi($team);
    }

    /**
     * Nährwert-Plausibilität: „davon"-Werte über ihrem Oberwert (Zucker > KH bzw.
     * gesättigte > Gesamt-Fett). Entsteht, wenn die LA-Abdeckung je Nährstoff
     * unterschiedlich ist (Ø über verschiedene LA-Mengen) — auf einem Label wäre
     * das ein Fehler. KEIN stilles Clampen (Ehrlichkeits-Prinzip): Summen-Signal.
     */
    public function naehrwertPlausi(Team $team, float $toleranzG = 0.1): int
    {
        $q = FoodAlchemistRecipe::visibleToTeam($team)
            ->whereNotNull('nutri_kcal_per_100g')
            ->where(fn ($w) => $w
                ->whereRaw('nutri_sugar_g_per_100g > nutri_carbs_g_per_100g + ?', [$toleranzG])
                ->orWhereRaw('nutri_saturated_fat_g_per_100g > nutri_fat_g_per_100g + ?', [$toleranzG]));

        $anzahl = (clone $q)->count();
        if ($anzahl === 0) {
            return 0;
        }
        $beispiele = (clone $q)->orderBy('id')->limit(10)
            ->get(['id', 'name', 'nutri_carbs_g_per_100g', 'nutri_sugar_g_per_100g', 'nutri_fat_g_per_100g', 'nutri_saturated_fat_g_per_100g'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'name' => $r->name,
                'kh' => (float) $r->nutri_carbs_g_per_100g, 'zucker' => (float) $r->nutri_sugar_g_per_100g,
                'fett' => (float) $r->nutri_fat_g_per_100g, 'gesfett' => (float) $r->nutri_saturated_fat_g_per_100g,
            ])->all();

        $this->signals->erzeuge(
            $team,
            SignalTyp::NaehrwertPlausi,
            SignalSeverity::Warnung,
            $anzahl . ' Rezepte mit unplausiblen Nährwerten (Zucker > KH / gesättigte > Fett)',
            [
                'dedup_key' => 'naehrwert-plausi',
                'description' => '„davon"-Wert liegt über dem Oberwert — Ursache ist meist ungleiche Nährwert-Abdeckung der Lieferantenartikel je GP (Ø über verschiedene LA-Mengen). Auf Labels/Foodbooks wäre das ein Deklarationsfehler — betroffene GP-Daten prüfen.',
                'payload' => ['anzahl' => $anzahl, 'beispiele' => $beispiele],
            ]
        );

        return 1;
    }

    /**
     * Datenqualität GP/LA: GPs mit requires_la, aber ohne Lead-LA bzw. ohne LAs.
     * Ein Summen-Signal (kein Dauerfeuer) mit Anzahl + Beispielen.
     */
    public function datenqualitaetGpLa(Team $team): int
    {
        // Scope = Team-Kette (GPs sind vererbt, wie margeUnterZiel) — vorher ungescoped über ALLE Teams
        $q = FoodAlchemistGp::visibleToTeam($team)
            ->where('requires_la', true)
            ->where(fn ($w) => $w->whereNull('lead_la_supplier_item_id')->orWhere('n_las_total', 0));

        $anzahl = (clone $q)->count();
        if ($anzahl === 0) {
            return 0;
        }
        $beispiele = (clone $q)->orderBy('id')->limit(10)->pluck('name', 'id')->all();

        $this->signals->erzeuge(
            $team,
            SignalTyp::DatenqualitaetGpLa,
            $anzahl > 100 ? SignalSeverity::Kritisch : SignalSeverity::Warnung,
            $anzahl . ' Grundprodukte ohne Lead-Lieferantenartikel',
            [
                'dedup_key' => 'datenqualitaet-gp-ohne-la',
                'description' => 'Diese GPs benötigen einen Lieferantenartikel, haben aber keinen Lead-LA bzw. keine LAs — Kalkulation und Allergen-Aggregation bleiben dadurch unvollständig.',
                'payload' => ['anzahl' => $anzahl, 'beispiele' => $beispiele],
            ]
        );

        return 1;
    }

    /**
     * Veraltete Preise: Lead-LAs, deren jüngster hinterlegter Preis älter als die
     * Schwelle ist (oder die keinen Preis haben). Summen-Signal (kein Dauerfeuer).
     */
    public function veraltetePreise(Team $team, int $tageSchwelle = 180): int
    {
        $grenze = now()->subDays($tageSchwelle)->format('Y-m-d H:i:s');

        $anzahl = DB::table('foodalchemist_gps as g')
            ->join('foodalchemist_supplier_items as i', 'i.id', '=', 'g.lead_la_supplier_item_id')
            ->leftJoin('foodalchemist_prices as p', function ($j) {
                $j->on('p.supplier_item_id', '=', 'i.id')->whereNull('p.deleted_at');
            })
            ->whereNotNull('g.lead_la_supplier_item_id')
            ->whereNull('g.deleted_at')
            ->whereIn('g.team_id', FoodAlchemistGp::teamAncestryIds($team))
            ->groupBy('g.id')
            ->havingRaw('MAX(p.status_valid_from) < ? OR MAX(p.status_valid_from) IS NULL', [$grenze])
            ->get(['g.id'])
            ->count();

        if ($anzahl === 0) {
            return 0;
        }

        $this->signals->erzeuge(
            $team,
            SignalTyp::VeraltetePreise,
            $anzahl > 200 ? SignalSeverity::Warnung : SignalSeverity::Info,
            $anzahl . ' Lead-Lieferantenartikel mit veraltetem Preis (> ' . $tageSchwelle . ' Tage)',
            [
                'dedup_key' => 'veraltete-preise',
                'description' => 'Der jüngste hinterlegte Preis dieser Lead-LAs ist älter als ' . $tageSchwelle . ' Tage (oder fehlt) — die Kalkulation rechnet evtl. mit Alt-Preisen.',
                'payload' => ['anzahl' => $anzahl, 'schwelle_tage' => $tageSchwelle],
            ]
        );

        return 1;
    }

    /**
     * Preis-Anomalie (#375 Stufe 1, statistisch): je GP über seine LAs (via
     * supplier_item_structures) den Aktiv-Preis (PriceService::activePriceSubquery,
     * set-basiert/kanonisch) nehmen; NUR innerhalb gleicher Einheit (unit_code) als
     * price-per-qty vergleichen (vermeidet Packungsgrößen-Falschtreffer); je GP+Einheit
     * mit ≥3 bepreisten LAs Median; LAs > ±50% Abweichung = Ausreißer → ein Signal je GP.
     * Stufe 2 (KI price.plausi je Ausreißer) folgt in #375.
     */
    public function preisAnomalie(Team $team, float $schwelle = 0.5, int $maxGps = 2000): int
    {
        $ps = app(PriceService::class);
        $rows = DB::table('foodalchemist_supplier_item_structures as s')
            ->join('foodalchemist_supplier_items as i', 'i.id', '=', 's.supplier_item_id')
            ->join('foodalchemist_gps as g', 'g.id', '=', 's.gp_id')
            ->whereNull('s.deleted_at')->whereNull('i.deleted_at')->whereNull('g.deleted_at')
            ->whereIn('g.team_id', FoodAlchemistGp::teamAncestryIds($team))
            ->where('g.n_las_total', '>=', 3)
            ->select('s.gp_id', 'g.name as gp_name', 'i.id as item_id', 'i.unit_code', 'i.qty')
            ->selectSub($ps->activePriceSubquery('i.id'), 'aktiv_preis')
            ->get();

        $treffer = 0;
        $verarbeitet = 0;
        foreach ($rows->groupBy('gp_id') as $gpId => $items) {
            if ($verarbeitet >= $maxGps) {
                \Illuminate\Support\Facades\Log::info("preisAnomalie: Cap {$maxGps} GPs erreicht — Rest übersprungen (team {$team->id}).");
                break;
            }
            $verarbeitet++;

            $ausreisser = [];
            // Vergleich nur innerhalb gleicher Einheit (price-per-qty)
            foreach (collect($items)->groupBy(fn ($r) => $r->unit_code ?? '?') as $grp) {
                $ppus = [];
                foreach ($grp as $r) {
                    if ($r->aktiv_preis === null) {
                        continue;
                    }
                    $qty = (float) ($r->qty ?? 0);
                    $ppus[] = ['item_id' => (int) $r->item_id, 'ppu' => $qty > 0 ? (float) $r->aktiv_preis / $qty : (float) $r->aktiv_preis, 'preis' => (float) $r->aktiv_preis];
                }
                if (count($ppus) < 3) {
                    continue;
                }
                $median = $this->median(array_column($ppus, 'ppu'));
                if ($median <= 0) {
                    continue;
                }
                foreach ($ppus as $p) {
                    $abw = abs($p['ppu'] - $median) / $median;
                    if ($abw > $schwelle) {
                        $ausreisser[] = ['item_id' => $p['item_id'], 'preis' => round($p['preis'], 2), 'ppu' => round($p['ppu'], 4), 'median_ppu' => round($median, 4), 'abw_pct' => (int) round($abw * 100)];
                    }
                }
            }
            if ($ausreisser === []) {
                continue;
            }
            $maxAbw = max(array_column($ausreisser, 'abw_pct'));
            $this->signals->erzeuge(
                $team,
                SignalTyp::PreisAnomalie,
                $maxAbw >= 150 ? SignalSeverity::Kritisch : SignalSeverity::Warnung,
                $items->first()->gp_name . ' — ' . count($ausreisser) . ' Preis-Ausreißer (bis ' . $maxAbw . ' %)',
                [
                    'dedup_key' => 'preis-anomalie-gp-' . $gpId,
                    'ref_type' => 'gp',
                    'ref_id' => (int) $gpId,
                    'description' => 'Lieferantenpreise weichen innerhalb gleicher Einheit stark vom Median ab — prüfen (Tippfehler, Datenfehler, Premium oder echter Ausreißer).',
                    'payload' => ['ausreisser' => array_slice($ausreisser, 0, 10), 'max_abw_pct' => $maxAbw],
                ]
            );
            $treffer++;
        }

        return $treffer;
    }

    /** Median einer (unsortierten) Zahlenliste. */
    private function median(array $werte): float
    {
        sort($werte);
        $n = count($werte);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $werte[$mid] : ((float) $werte[$mid - 1] + (float) $werte[$mid]) / 2;
    }

    /**
     * Marge unter Ziel: Verkaufsrezepte (Gerichte), deren HK2-Deckungsbeitrag (db_pct)
     * unter der Zielmarge (settings.margePct, aus recipeHk) liegt. Ein Signal je Gericht
     * (ref auf das Gericht), severity nach Schwere (negativer DB = kritisch).
     */
    public function margeUnterZiel(Team $team): int
    {
        $kalk = app(KalkulationService::class);
        $gerichte = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNotNull('vk_netto')->where('vk_netto', '>', 0)->get();

        $n = 0;
        foreach ($gerichte as $r) {
            $hk = $kalk->recipeHk($team, $r);
            $db = $hk['db_pct'] ?? null;
            $ziel = (float) ($hk['marge_pct'] ?? 0);
            if ($db === null || $ziel <= 0 || $db >= $ziel) {
                continue;
            }
            $this->signals->erzeuge(
                $team,
                SignalTyp::MargeUnterZiel,
                $db < 0 ? SignalSeverity::Kritisch : SignalSeverity::Warnung,
                $r->name . ' — DB ' . number_format((float) $db, 1, ',', '.') . ' % unter Ziel ' . number_format($ziel, 1, ',', '.') . ' %',
                [
                    'dedup_key' => 'marge-recipe-' . $r->id,
                    'ref_type' => 'recipe',
                    'ref_id' => $r->id,
                    'description' => 'Deckungsbeitrag unter der Zielmarge — Verkaufspreis erhöhen oder Wareneinsatz/Vollkosten senken.',
                    'payload' => ['db_pct' => (float) $db, 'ziel_pct' => $ziel, 'vk_netto' => (float) $r->vk_netto],
                ]
            );
            $n++;
        }

        return $n;
    }

    /**
     * #379+: Wareneinsatz über Ziel — Verkaufsrezepte, deren Food-Cost-Quote
     * (wareneinsatz_pct aus recipeHk = Wareneinsatz/VK) über der Ziel-Wareneinsatzquote
     * (settings.zielWareneinsatzPct) liegt. Ein Signal je Gericht. Gastro-nativster KPI;
     * ergänzt „Marge unter Ziel" um die Einkaufs-/Rezeptur-Seite.
     */
    public function wareneinsatzUeberZiel(Team $team): int
    {
        $kalk = app(KalkulationService::class);
        $ziel = app(TeamSettingsService::class)->zielWareneinsatzPct($team);
        if ($ziel <= 0) {
            return 0;
        }
        $gerichte = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNotNull('vk_netto')->where('vk_netto', '>', 0)->get();

        $n = 0;
        foreach ($gerichte as $r) {
            $we = $kalk->recipeHk($team, $r)['wareneinsatz_pct'] ?? null;
            if ($we === null || $we <= $ziel) {
                continue;
            }
            $this->signals->erzeuge(
                $team,
                SignalTyp::WareneinsatzUeberZiel,
                // > 1,5× Ziel = deutlich zu teuer → kritisch, sonst Warnung
                $we > $ziel * 1.5 ? SignalSeverity::Kritisch : SignalSeverity::Warnung,
                $r->name . ' — Wareneinsatz ' . number_format((float) $we, 1, ',', '.') . ' % über Ziel ' . number_format($ziel, 1, ',', '.') . ' %',
                [
                    'dedup_key' => 'we-quote-recipe-' . $r->id,
                    'ref_type' => 'recipe',
                    'ref_id' => $r->id,
                    'description' => 'Food-Cost über dem Ziel — günstigeren Lead-LA prüfen, Rezeptur/Portion anpassen oder Verkaufspreis erhöhen.',
                    'payload' => ['wareneinsatz_pct' => (float) $we, 'ziel_pct' => $ziel, 'vk_netto' => (float) $r->vk_netto],
                ]
            );
            $n++;
        }

        return $n;
    }
}
