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
    public function __construct(
        private SignalService $signals,
        private DataQualityService $dataQuality,
    ) {
    }

    /** Alle Detektoren; Rückgabe = Anzahl erzeugter/aktualisierter Signale. */
    public function laufen(Team $team): int
    {
        return $this->datenqualitaetGpLa($team)
            + $this->veraltetePreise($team)
            + $this->preisAnomalie($team)
            + $this->preisSprungMargeImpact($team)
            + $this->margeUnterZiel($team)
            + $this->wareneinsatzUeberZiel($team)
            + $this->vkAnpassungEmpfohlen($team)
            + $this->vertragsfristFaellig($team)
            + $this->naehrwertPlausi($team)
            + $this->dataQuality->emittiereSignale($team);   // Datenqualitäts-Kaskade-Ampel (P1) mit im Scheduler
    }

    /**
     * R2.1 — Preis-Alarm + Marge-Impact: Lead-LA-Preis eines GP springt seit Kurzem
     * um ≥ Schwelle (team-konfigurierbar, TeamSettingsService::preisAlarmSchwellePct) →
     * ein Signal je GP mit dem konkreten Impact: N betroffene Rezepte, M Konzepte,
     * Marge-Delta (€ + W%-Punkte). Zeigt zusätzlich, wenn ein Nicht-Lead-LA jetzt
     * günstiger ist (Chance, nicht nur Risiko).
     *
     * Nur LEAD-LA-Sprünge (die treiben den GP-EK); nur „frische" Änderungen
     * (Vorgänger valid_to ≥ Lookback) — kein Erst-Lauf-Dauerfeuer, Dedup je neuem Preis.
     * Marge-Delta über den Preis-Ratio: aktuelle GP-Zeilenkosten (neuer Preis) skaliert
     * auf den Vorpreis → alt-EK; MargeService alt vs. neu. Direkt-Nutzer je GP (MVP;
     * verschachtelte Eltern-Rezepte werden über die Sub-Rezepte separat erfasst).
     */
    public function preisSprungMargeImpact(Team $team, ?float $schwellePct = null, int $lookbackTage = 60, int $maxGps = 500): int
    {
        $schwelle = $schwellePct ?? app(TeamSettingsService::class)->preisAlarmSchwellePct($team);
        $cutoff = now()->subDays($lookbackTage)->format('Y-m-d H:i:s');

        // GPs mit Lead-LA im Team-Scope: [gp_id => lead_la_id]
        $leads = DB::table('foodalchemist_gps')
            ->whereIn('team_id', FoodAlchemistGp::teamAncestryIds($team))
            ->whereNull('deleted_at')->whereNotNull('lead_la_supplier_item_id')
            ->pluck('lead_la_supplier_item_id', 'id');
        if ($leads->isEmpty()) {
            return 0;
        }
        $leadIds = $leads->values()->map(fn ($v) => (int) $v)->unique()->all();

        // nur Lead-LAs mit kürzlicher Preisänderung (Vorgänger-Zeile jüngst geschlossen)
        $recent = DB::table('foodalchemist_prices')
            ->whereIn('supplier_item_id', $leadIds)->whereNull('deleted_at')
            ->whereNotNull('valid_to')->where('valid_to', '>=', $cutoff)
            ->distinct()->pluck('supplier_item_id')->map(fn ($v) => (int) $v)->flip();
        if ($recent->isEmpty()) {
            return 0;
        }

        $preisSvc = app(PriceService::class);
        $recompute = app(RecipeRecomputeService::class);
        $margeSvc = app(MargeService::class);
        $trend = $preisSvc->preisTrendBulk(array_keys($recent->all()));

        $n = 0;
        $verarbeitet = 0;
        foreach ($leads as $gpId => $leadId) {
            $leadId = (int) $leadId;
            if (! isset($recent[$leadId], $trend[$leadId])) {
                continue;
            }
            $t = $trend[$leadId];
            if (! $t['plausibel'] || abs((float) $t['delta_pct']) < $schwelle) {
                continue;
            }
            if ($verarbeitet >= $maxGps) {
                break;
            }
            $verarbeitet++;

            $delta = (float) $t['delta_pct'];
            $ratio = $t['vorher'] > 0 ? $t['aktuell'] / $t['vorher'] : 0.0; // neu/alt
            if ($ratio <= 0) {
                continue;
            }
            $teurer = $delta > 0;

            // Betroffene Rezepte: Direkt-Nutzer + transitiv alle Eltern (BFS über referenced_recipe_id).
            // VK-Gerichte nutzen GPs fast nie direkt, sondern über Basisrezepte — daher zwingend transitiv.
            $direkt = DB::table('foodalchemist_recipe_ingredients')
                ->where('gp_id', (int) $gpId)->whereNull('deleted_at')
                ->distinct()->pluck('recipe_id')->map(fn ($v) => (int) $v)->all();
            if ($direkt === []) {
                continue;
            }
            $affected = $this->betroffeneRezeptBaum($direkt);

            $recipes = FoodAlchemistRecipe::visibleToTeam($team)->whereIn('id', $affected)->get();
            $nRecipes = $recipes->count();
            $gerichte = $recipes->filter(fn ($r) => $r->is_sales_recipe && $r->sales_net !== null && (float) $r->sales_net > 0)->values();
            $nGerichte = $gerichte->count();

            $sumMargeDelta = 0.0;
            $worstWpct = 0.0;
            $beispiele = [];
            $recCache = [];
            $lineCache = [];
            $totalCache = [];
            $expCache = [];
            $margeZahl = 0;
            foreach ($gerichte as $rec) {
                if ($margeZahl >= 150) {
                    break; // Marge-Cap (Betroffenen-Count bleibt vollständig)
                }
                $margeZahl++;
                $exposure = $this->gpExposure((int) $rec->id, (int) $gpId, $recompute, $recCache, $lineCache, $totalCache, $expCache);
                $newEk = $totalCache[(int) $rec->id] ?? null;
                if ($exposure === null || $exposure <= 0 || $newEk === null || $newEk <= 0) {
                    continue;
                }
                $ekDelta = $exposure * (1 - 1 / $ratio);   // >0 = teurer geworden
                $oldEk = $newEk - $ekDelta;
                $vk = (float) $rec->sales_net;
                $mNeu = $margeSvc->marge($vk, $newEk);
                $mAlt = $margeSvc->marge($vk, $oldEk);
                if ($mNeu === null || $mAlt === null) {
                    continue;
                }
                $mdelta = round($mNeu['marge_eur'] - $mAlt['marge_eur'], 2);
                $wpctDelta = round($mNeu['wareneinsatz_pct'] - $mAlt['wareneinsatz_pct'], 1);
                $sumMargeDelta += $mdelta;
                if (abs($wpctDelta) > abs($worstWpct)) {
                    $worstWpct = $wpctDelta;
                }
                if (count($beispiele) < 12) {
                    $beispiele[] = [
                        'recipe_id' => (int) $rec->id, 'name' => $rec->name,
                        'marge_pct_alt' => $mAlt['marge_pct'], 'marge_pct_neu' => $mNeu['marge_pct'],
                        'marge_delta_eur' => $mdelta,
                    ];
                }
            }

            // betroffene Konzepte über die betroffenen Gerichte (direkte Slots + über Pakete)
            $gerichtIds = $gerichte->pluck('id')->map(fn ($v) => (int) $v)->all();
            $conceptDirekt = $gerichtIds === [] ? [] : DB::table('foodalchemist_concept_slots')
                ->whereIn('sales_recipe_id', $gerichtIds)->whereNull('deleted_at')
                ->whereNotNull('concept_id')->distinct()->pluck('concept_id')->all();
            $conceptPaket = $gerichtIds === [] ? [] : DB::table('foodalchemist_package_dishes AS pd')
                ->join('foodalchemist_concept_slots AS cs', 'cs.package_id', '=', 'pd.package_id')
                ->whereIn('pd.sales_recipe_id', $gerichtIds)->whereNull('cs.deleted_at')
                ->whereNotNull('cs.concept_id')->distinct()->pluck('cs.concept_id')->all();
            $nConcepts = count(array_unique(array_merge($conceptDirekt, $conceptPaket)));

            // Chance: günstigster Nicht-Lead-LA je Einheit vs. Lead
            $chance = $this->guenstigereAlternative($preisSvc, (int) $gpId, $leadId, $schwelle);

            $gpName = DB::table('foodalchemist_gps')->where('id', $gpId)->value('name') ?? ('GP ' . $gpId);
            $richtung = $teurer ? '+' : '';
            $titel = $gpName . ' — Lead-Preis ' . $richtung . number_format($delta, 1, ',', '.') . ' % → '
                . $nGerichte . ' Gericht(e)' . ($nConcepts ? ', ' . $nConcepts . ' Konzept(e)' : '')
                . ($sumMargeDelta != 0.0 ? ', Marge ' . number_format($sumMargeDelta, 2, ',', '.') . ' €' : '');

            $severity = ! $teurer
                ? SignalSeverity::Info
                : ((abs($delta) >= $schwelle * 2 || $sumMargeDelta < 0) ? SignalSeverity::Kritisch : SignalSeverity::Warnung);

            $this->signals->erzeuge(
                $team,
                SignalTyp::PreisSprungMargeImpact,
                $severity,
                $titel,
                [
                    'dedup_key' => 'preis-sprung-gp-' . $gpId . '-' . number_format($t['aktuell'], 2, '.', ''),
                    'ref_type' => 'gp',
                    'ref_id' => (int) $gpId,
                    'description' => 'Der Lead-Lieferantenartikel dieses Grundprodukts hat sich um '
                        . number_format($delta, 1, ',', '.') . ' % verändert (' . number_format((float) $t['vorher'], 2, ',', '.')
                        . ' € → ' . number_format((float) $t['aktuell'], 2, ',', '.') . ' €). '
                        . ($teurer ? 'Marge sinkt' : 'Marge steigt') . ' in den betroffenen Gerichten.'
                        . ($chance !== null ? ' Günstigere Alternative verfügbar: ' . $chance['label'] . ' (' . $chance['diff_pct'] . ' %).' : ''),
                    'payload' => [
                        'gp_id' => (int) $gpId, 'gp_name' => $gpName,
                        'lead_la_id' => $leadId,
                        'preis_alt' => (float) $t['vorher'], 'preis_neu' => (float) $t['aktuell'], 'delta_pct' => $delta,
                        'n_recipes' => $nRecipes, 'n_gerichte' => $nGerichte, 'n_concepts' => $nConcepts,
                        'marge_delta_eur' => round($sumMargeDelta, 2), 'wpct_delta' => $worstWpct,
                        'beispiele' => $beispiele,
                        'guenstigere_alternative' => $chance,
                    ],
                ]
            );
            $n++;
        }

        return $n;
    }

    /**
     * Betroffener Rezept-Baum: Direkt-Nutzer + alle transitiven Eltern (BFS über
     * referenced_recipe_id nach oben). So werden VK-Gerichte gefunden, die einen GP
     * nur über Basisrezepte nutzen. Rückgabe = alle betroffenen recipe_ids.
     *
     * @param  list<int>  $direktIds
     * @return list<int>
     */
    private function betroffeneRezeptBaum(array $direktIds, int $maxTiefe = 6): array
    {
        $alle = array_fill_keys($direktIds, true);
        $frontier = $direktIds;
        for ($d = 0; $d < $maxTiefe && $frontier !== []; $d++) {
            $eltern = DB::table('foodalchemist_recipe_ingredients')
                ->whereIn('referenced_recipe_id', $frontier)->whereNull('deleted_at')
                ->distinct()->pluck('recipe_id')->map(fn ($v) => (int) $v)->all();
            $neu = [];
            foreach ($eltern as $e) {
                if (! isset($alle[$e])) {
                    $alle[$e] = true;
                    $neu[] = $e;
                }
            }
            $frontier = $neu;
        }

        return array_keys($alle);
    }

    /**
     * Exakte €-Exposure eines GP innerhalb eines Rezept-Baums (rekursiv, memoisiert):
     * direkte GP-Zeilen + anteilig die Exposure referenzierter Sub-Rezepte
     * (Sub-Anteil = Zeilenkosten × subExposure/subTotal). Setzt totalCache[recipeId]
     * als Gesamt-EK des Rezepts (= Σ Zeilenkosten) mit.
     */
    private function gpExposure(int $recipeId, int $gpId, RecipeRecomputeService $recompute, array &$recCache, array &$lineCache, array &$totalCache, array &$expCache, int $tiefe = 0): ?float
    {
        if (isset($expCache[$recipeId])) {
            return $expCache[$recipeId];
        }
        if ($tiefe > 5) {
            return 0.0;
        }
        $rec = $recCache[$recipeId] ??= FoodAlchemistRecipe::with('ingredients')->find($recipeId);
        if ($rec === null) {
            $totalCache[$recipeId] = 0.0;

            return $expCache[$recipeId] = 0.0;
        }
        $lines = $lineCache[$recipeId] ??= $recompute->zeilenKostenUndMassen($rec);
        $total = 0.0;
        foreach ($lines as $l) {
            if ($l['kosten'] !== null) {
                $total += (float) $l['kosten'];
            }
        }
        $totalCache[$recipeId] = $total;

        $exp = 0.0;
        foreach ($rec->ingredients as $ing) {
            $lk = isset($lines[$ing->id]) && $lines[$ing->id]['kosten'] !== null ? (float) $lines[$ing->id]['kosten'] : 0.0;
            if ($lk <= 0.0) {
                continue;
            }
            if ((int) $ing->gp_id === $gpId) {
                $exp += $lk;
            } elseif ($ing->referenced_recipe_id !== null) {
                $subId = (int) $ing->referenced_recipe_id;
                $subExp = $this->gpExposure($subId, $gpId, $recompute, $recCache, $lineCache, $totalCache, $expCache, $tiefe + 1);
                $subTotal = $totalCache[$subId] ?? 0.0;
                if ($subExp !== null && $subExp > 0.0 && $subTotal > 0.0) {
                    $exp += $lk * ($subExp / $subTotal);
                }
            }
        }

        $expCache[$recipeId] = $exp;
        // Speicher: schweres Rezept-Modell (inkl. Zutaten) + Zeilenkosten nach dem
        // Memoisieren freigeben — Wiederbesuche treffen den expCache-Early-Return.
        // Spiegelt MargeImpactService::gpSetExposure (Peak-Kappung bei großen Bäumen).
        unset($recCache[$recipeId], $lineCache[$recipeId]);

        return $exp;
    }

    /**
     * Günstigster aktiver Nicht-Lead-LA eines GP vs. Lead (gleiche Einheit, €/Einheit),
     * wenn ≥ Schwelle günstiger. Für den Chance-Teil des Preis-Alarms.
     *
     * @return array{item_id:int,label:string,diff_pct:float}|null
     */
    private function guenstigereAlternative(PriceService $preisSvc, int $gpId, int $leadId, float $schwelle): ?array
    {
        $las = DB::table('foodalchemist_supplier_items AS i')
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'i.id')
            ->where('s.gp_id', $gpId)->whereNull('s.deleted_at')->whereNull('i.deleted_at')
            ->select('i.id', 'i.designation', 'i.qty', 'i.unit_code')
            ->selectSub($preisSvc->activePriceSubquery('i.id')->toBase(), 'aktiver_preis')
            ->get();

        $lead = $las->firstWhere('id', $leadId);
        if ($lead === null || $lead->aktiver_preis === null) {
            return null;
        }
        $leadPu = $preisSvc->vergleichspreis($lead, (float) $lead->aktiver_preis);
        if ($leadPu === null) {
            return null;
        }

        $best = null;
        foreach ($las as $la) {
            if ((int) $la->id === $leadId || $la->aktiver_preis === null) {
                continue;
            }
            $pu = $preisSvc->vergleichspreis($la, (float) $la->aktiver_preis);
            if ($pu === null || $pu['unit'] !== $leadPu['unit'] || $pu['value'] <= 0) {
                continue;
            }
            $diffPct = ($pu['value'] - $leadPu['value']) / $leadPu['value'] * 100; // negativ = günstiger
            if ($diffPct <= -$schwelle && ($best === null || $diffPct < $best['diff_pct'])) {
                $best = ['item_id' => (int) $la->id, 'label' => $la->designation, 'diff_pct' => round($diffPct, 1)];
            }
        }

        return $best;
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
                    $ppus[] = ['item_id' => (int) $r->item_id, 'ppu' => $qty > 0 ? (float) $r->aktiv_preis / $qty : (float) $r->aktiv_preis, 'price' => (float) $r->aktiv_preis];
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
                        $ausreisser[] = ['item_id' => $p['item_id'], 'price' => round($p['price'], 2), 'ppu' => round($p['ppu'], 4), 'median_ppu' => round($median, 4), 'abw_pct' => (int) round($abw * 100)];
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
            ->whereNotNull('sales_net')->where('sales_net', '>', 0)->get();

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
                    'payload' => ['db_pct' => (float) $db, 'ziel_pct' => $ziel, 'sales_net' => (float) $r->sales_net],
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
            ->whereNotNull('sales_net')->where('sales_net', '>', 0)->get();

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
                    'payload' => ['wareneinsatz_pct' => (float) $we, 'ziel_pct' => $ziel, 'sales_net' => (float) $r->sales_net],
                ]
            );
            $n++;
        }

        return $n;
    }

    /**
     * R2.5 — VK-Anpassung empfohlen: der LIVE gerechnete VK einer Darreichung weicht
     * vom zuletzt FREIGEGEBENEN Snapshot über die Leitplanke (max_vk_delta_pct) ab.
     * Trennung Live-Marge ↔ veröffentlichter VK: das Signal fordert eine bewusste
     * Freigabe (Batch) — ohne die bleibt der Kundenpreis (Snapshot) unverändert.
     * Ein Signal je Darreichung; Richtung (erhöhen/senken) + Delta im Payload.
     */
    public function vkAnpassungEmpfohlen(Team $team): int
    {
        $mindest = app(TeamSettingsService::class)->mindestMarginPct($team);
        $n = 0;
        foreach (app(VkSnapshotService::class)->pending($team) as $p) {
            $this->signals->erzeuge(
                $team,
                SignalTyp::VkAnpassungEmpfohlen,
                // Preissenkung (Marge fällt) ist dringlicher als eine mögliche Erhöhung.
                $p['richtung'] === 'erhoehen' ? SignalSeverity::Kritisch : SignalSeverity::Warnung,
                $p['recipe_name'] . ' — freigegebener VK ' . number_format($p['published_net'], 2, ',', '.')
                    . ' € vs. live ' . number_format($p['live_net'], 2, ',', '.') . ' € (Δ '
                    . number_format($p['delta_pct'], 1, ',', '.') . ' %, ' . $p['richtung'] . ')',
                [
                    'dedup_key' => 'vk-anpassung-presentation-' . $p['presentation_id'] . '-' . $p['live_net'],
                    'ref_type' => 'recipe',
                    'ref_id' => $p['recipe_id'],
                    'description' => 'Der intern gerechnete VK weicht vom freigegebenen Kundenpreis ab. '
                        . 'Bewusst freigeben (Batch) oder Live-Kalkulation prüfen — kein stiller Kunden-Preissprung.',
                    'payload' => [
                        'presentation_id' => $p['presentation_id'],
                        'published_net' => $p['published_net'],
                        'live_net' => $p['live_net'],
                        'delta_pct' => $p['delta_pct'],
                        'richtung' => $p['richtung'],
                        'mindest_marge_pct' => $mindest,
                    ],
                ]
            );
            $n++;
        }

        return $n;
    }

    /**
     * R9.1 (E7) — Vertragsfrist fällig: die Kündigungs-Deadline eines Lieferanten-
     * Dokuments (Laufzeitende − Kündigungsfrist) liegt im Vorlauf-Fenster. Ein Signal
     * je Dokument; Muster wie veraltetePreise, aber datumsgetrieben.
     */
    public function vertragsfristFaellig(Team $team, int $lookaheadDays = 30): int
    {
        $n = 0;
        foreach (app(SupplierAgreementService::class)->documentsDueForNotice($team, $lookaheadDays) as $d) {
            $deadline = $d->noticeDeadline();
            $supplierName = optional($d->supplier)->name ?? ('Lieferant #' . $d->supplier_id);
            $ueberfaellig = $deadline !== null && $deadline->isPast();
            $this->signals->erzeuge(
                $team,
                SignalTyp::VertragsfristFaellig,
                $ueberfaellig ? SignalSeverity::Kritisch : SignalSeverity::Warnung,
                $supplierName . ' — Kündigungsfrist ' . ($ueberfaellig ? 'überschritten' : 'läuft ab')
                    . ' am ' . $deadline?->format('d.m.Y') . ' (Vertrag bis ' . $d->term_end?->format('d.m.Y') . ')',
                [
                    'dedup_key' => 'vertragsfrist-doc-' . $d->id,
                    'ref_type' => 'supplier',
                    'ref_id' => (int) $d->supplier_id,
                    'description' => 'Kündigungs-/Verlängerungsentscheidung ansteht — Vertrag prüfen, ggf. rechtzeitig kündigen oder nachverhandeln.',
                    'payload' => [
                        'document_id' => (int) $d->id,
                        'kind' => $d->kind,
                        'term_end' => $d->term_end?->toDateString(),
                        'notice_period_days' => $d->notice_period_days,
                        'notice_deadline' => $deadline?->toDateString(),
                    ],
                ]
            );
            $n++;
        }

        return $n;
    }
}
