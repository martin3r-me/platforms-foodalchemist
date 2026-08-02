<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSignalSnapshot;

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
        private SignalTrendService $trend,
        private SignalPolicyService $policies,
    ) {
    }

    /** Spec 21 · E3 — ab diesem Zuwachs gegenüber dem Vorlauf gilt ein Zähler als abgedriftet. */
    private const DRIFT_MIN_PCT = 20.0;

    /** … und erst ab dieser absoluten Zunahme (sonst wäre 1 → 2 ein „+100 %"-Alarm). */
    private const DRIFT_MIN_ABS = 5;

    /**
     * Alle Detektoren; Rückgabe = Anzahl erzeugter/aktualisierter Signale.
     *
     * Reihenfolge ist Absicht: Detektoren → Snapshot → Drift.
     * Der Snapshot schreibt den Zähler-Stand in die Zeitreihe (Spec 21 · E1) —
     * derselbe Fold-in-Gedanke wie bei der DQ-Ampel: der Scheduler fährt **diesen**
     * Command, also muss der Trend hier entstehen und nicht in einem Zweit-Job, der
     * auf demo nie eingehängt wird. Bewusst **nach** allen Emissionen, damit die
     * Signal-Seite des Snapshots diesen Lauf schon enthält.
     * Der Drift-Vergleich (E3) läuft danach, weil er genau diesen frischen Punkt gegen
     * den Vorlauf hält. Konsequenz, bewusst in Kauf genommen: die Drift-Signale dieses
     * Laufs stehen erst im *nächsten* Snapshot — sie zählen sich sonst selbst mit.
     */
    public function laufen(Team $team): int
    {
        $n = $this->detektoren($team);
        $this->trend->schreibeSnapshot($team);

        return $n + $this->qualitaetsDrift($team);
    }

    private function detektoren(Team $team): int
    {
        return $this->datenqualitaetGpLa($team)
            + $this->veraltetePreise($team)
            + $this->preisAnomalie($team)
            + $this->preisSprungMargeImpact($team)
            + $this->margeUnterZiel($team)
            + $this->wareneinsatzUeberZiel($team)
            + $this->wareneinsatzIstAbweichung($team)
            + $this->vkAnpassungEmpfohlen($team)
            + $this->vertragsfristFaellig($team)
            + $this->widerspruchWissenGraph($team)
            + $this->naehrwertPlausi($team)
            + $this->dataQuality->emittiereSignale($team);   // Datenqualitäts-Kaskade-Ampel (P1) mit im Scheduler
    }

    /**
     * Spec 21 · E3 — Meta-Signal `qualitaet_drift`: ein Zähler ist gegenüber dem
     * Vorlauf gestiegen. Alarmiert bei **Veränderung**, nicht bei Bestand — deshalb
     * überlebt es den Rausch-Guard (E2): eine bekannte, akzeptierte Lage darf sich
     * trotzdem nicht heimlich vergrößern. Nur `muted` schaltet auch den Drift ab.
     *
     * Vier Sperren gegen Fehlalarm:
     *  1. `previous === null` (im Vorlauf nicht gemessen, also neuer Check) ist keine
     *     Verschlechterung — genau dafür ist die Zeitreihe dicht geschrieben (E1).
     *  2. Ein Zuwachs braucht **beides**: ≥ DRIFT_MIN_PCT und ≥ DRIFT_MIN_ABS, sonst
     *     wäre 1 → 2 ein „+100 %"-Alarm. Ausnahme ist das **Neuauftreten** (0 → n):
     *     ein Befund, der behoben war und wiederkommt, ist die stärkste Aussage der
     *     ganzen Reihe und zählt unabhängig von der Menge.
     *  3. Dieselbe Lage wird oft zweimal gemessen — als Lücken-Metrik der Ampel *und*
     *     als offene Signale desselben Typs. Die Ampel-Seite gewinnt, die Signal-Seite
     *     wird übersprungen (sonst zwei Drift-Signale für einen Sachverhalt).
     *  4. Kein Drift über den Drift selbst.
     *
     * Schweregrad bleibt `Warnung`: die Drift-Aussage ist „es wird schlechter", die
     * Schwere des Befunds selbst steht am zugrundeliegenden Signal. Dort hängt auch
     * der Fixer — das Drift-Signal bekommt bewusst **keinen** Knopf (es trägt kein
     * `metrik` im Payload), damit niemand über die Trend-Zeile blind einen Massen-Fix
     * auslöst statt den eigentlichen Befund anzusehen.
     */
    public function qualitaetsDrift(Team $team): int
    {
        $u = $this->trend->uebersicht($team);
        if ($u['previous_at'] === null) {
            return 0; // erster Lauf — es gibt nichts zu vergleichen
        }

        $dqTypen = [];
        foreach ($u['metriken'] as $m) {
            if ($m['source'] === FoodAlchemistSignalSnapshot::SOURCE_DQ && $m['signal_type'] !== null) {
                $dqTypen[$m['signal_type']] = true;
            }
        }

        $n = 0;
        foreach ($u['metriken'] as $m) {
            $vorher = $m['previous'];
            $delta = (int) ($m['delta'] ?? 0);
            if ($vorher === null || $delta <= 0) {
                continue;
            }
            $typ = $m['signal_type'] !== null ? SignalTyp::tryFrom($m['signal_type']) : null;
            if ($typ === SignalTyp::QualitaetDrift) {
                continue;
            }
            if ($m['source'] === FoodAlchemistSignalSnapshot::SOURCE_SIGNALS && isset($dqTypen[$m['metric_key']])) {
                continue;
            }
            if ($this->policies->driftStumm($team, $typ)) {
                continue;
            }
            $neuauftreten = $vorher === 0;
            $pct = $m['pct'];
            if (! $neuauftreten && ($delta < self::DRIFT_MIN_ABS || ($pct ?? 0.0) < self::DRIFT_MIN_PCT)) {
                continue;
            }

            $this->signals->erzeuge(
                $team,
                SignalTyp::QualitaetDrift,
                SignalSeverity::Warnung,
                $m['label'] . ': ' . $vorher . ' → ' . $m['count'] . ' (+' . $delta . ')',
                [
                    'dedup_key' => 'drift:' . $m['source'] . ':' . $m['metric_key'],
                    'description' => $neuauftreten
                        ? 'War beim letzten Lauf bei 0 und ist wieder aufgetreten — der Befund kommt zurück. Ursache am zugrundeliegenden Signal ansehen.'
                        : 'Seit dem letzten Lauf um ' . ($pct !== null ? $pct . ' %' : '+' . $delta) . ' gestiegen. Ursache am zugrundeliegenden Signal ansehen.',
                    // Bewusst OHNE 'metrik': kein Fix-Knopf an der Trend-Zeile (s. Docblock).
                    'payload' => [
                        'drift_metric' => $m['metric_key'],
                        'drift_source' => $m['source'],
                        'anzahl' => (int) $m['count'],
                        'vorher' => $vorher,
                        'delta' => $delta,
                        'pct' => $pct,
                        'neuauftreten' => $neuauftreten,
                        'measured_at' => $u['measured_at'],
                        'previous_at' => $u['previous_at'],
                    ],
                    'source' => 'trend',
                ]
            );
            $n++;
        }

        return $n;
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
     *
     * **V-050 · `$gpIds`:** Ein Aufrufer, der weiß, welche GPs er gerade bewegt hat
     * (Kanal-B-Import), übergibt sie — dann ersetzt diese Menge die Team-weite Suche,
     * alles danach bleibt identisch (eine Schwelle, ein Dedup-Key, ein Text; kein zweiter
     * Detektor). Ohne den Parameter zwei Schäden auf einmal: Team-weiter Scan für vier
     * Artikel im kleinen Fall — und im großen die *stille Auslassung*, weil jenseits von
     * `$maxGps` die `pluck`-Reihenfolge entscheidet, welche bewegten GPs kein Signal
     * bekommen. Der Deckel gehört damit dem Scheduler-Pfad; wer die exakte Menge kennt,
     * setzt ihn auf ihre Größe und kann eine Kappung beim Namen nennen.
     *
     * @param  ?array<int>  $gpIds  bewegte GPs; null = Team-weite Suche (Scheduler)
     */
    public function preisSprungMargeImpact(Team $team, ?float $schwellePct = null, int $lookbackTage = 60, int $maxGps = 500, ?array $gpIds = null): int
    {
        $schwelle = $schwellePct ?? app(TeamSettingsService::class)->preisAlarmSchwellePct($team);
        $cutoff = now()->subDays($lookbackTage)->format('Y-m-d H:i:s');

        // GPs mit Lead-LA im Team-Scope: [gp_id => lead_la_id]
        // Der Team-Scope bleibt AUCH bei gesetztem $gpIds bestehen (D1: eine übergebene
        // Fremd-GP-ID darf keine Sichtbarkeit erzeugen, die es sonst nicht gibt).
        $leads = DB::table('foodalchemist_gps')
            ->whereIn('team_id', FoodAlchemistGp::teamAncestryIds($team))
            ->when($gpIds !== null, fn ($q) => $q->whereIn('id', $gpIds === [] ? [0] : $gpIds))
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
        $ziel = app(TeamSettingsService::class)->zielWareneinsatzPct($team);
        if ($ziel <= 0) {
            return 0;
        }
        $gerichte = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNotNull('sales_net')->where('sales_net', '>', 0)->get();

        $n = 0;
        foreach ($gerichte as $r) {
            $n += $this->wareneinsatzUeberZielFuer($team, $r, $ziel) ? 1 : 0;
        }

        return $n;
    }

    /**
     * Dieselbe Regel für EIN Gericht (03·L8): die KI-Kaskade meldet ihren Ausreißer
     * sofort, statt bis zum nächsten Scheduler-Lauf zu warten. Bewusst als
     * Herausziehen statt als zweite Prüfstelle — Schwelle, Schweregrad-Leiter,
     * Dedup-Key und Text bleiben an genau einem Ort; ein zweiter Emittent würde
     * dasselbe Signal mit eigener Auslegung schreiben.
     *
     * `$we` kann übergeben werden, wenn der Aufrufer die Quote schon hat (03·L8:
     * die Kaskade rechnet sie aus der Darreichung — `ek_portion` gegen `sales_net`,
     * dieselbe Menge auf beiden Seiten). Ohne Übergabe bleibt es beim Batch-Weg über
     * `recipeHk`. Dass beide Wege bei `sales_unit_count > 1` auseinanderlaufen, ist
     * ein Bestands-Widerspruch → V-041, hier bewusst nicht mit-entschieden.
     *
     * @return bool true, wenn ein Signal erzeugt/aktualisiert wurde
     */
    public function wareneinsatzUeberZielFuer(Team $team, FoodAlchemistRecipe $r, ?float $ziel = null, ?float $we = null): bool
    {
        $ziel ??= app(TeamSettingsService::class)->zielWareneinsatzPct($team);
        if ($ziel <= 0) {
            return false;
        }
        $we ??= $this->kalkulation()->recipeHk($team, $r)['wareneinsatz_pct'] ?? null;
        if ($we === null || $we <= $ziel) {
            return false;
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
                'payload' => ['wareneinsatz_pct' => (float) $we, 'ziel_pct' => $ziel, 'sales_net' => $r->sales_net !== null ? (float) $r->sales_net : null],
            ]
        );

        return true;
    }

    /** Ein Kalkulator je Lauf — der Batch-Pfad löste ihn früher einmal vor der Schleife auf. */
    private ?KalkulationService $kalkMemo = null;

    private function kalkulation(): KalkulationService
    {
        return $this->kalkMemo ??= app(KalkulationService::class);
    }

    /**
     * Spec 32 C4 — Wareneinsatz Ist ≠ Rezeptur.
     *
     * Anders als {@see self::wareneinsatzUeberZiel} (kalkulatorisch, je Gericht) ist das hier
     * eine MESSUNG über einen Zeitraum: was wurde tatsächlich eingekauft gegenüber dem, was die
     * Rezepturen für den tatsächlich verkauften Absatz hergeben. Ein Signal je Team und Monat.
     *
     * Betrachtet wird der VOLLE Vormonat, nicht ein rollierendes Fenster: der Wert ist ohne
     * Inventur eine Perioden-Rechnung, und ein gleitendes Fenster würde jeden Tag eine andere
     * Zahl melden, ohne dass sich etwas geändert hätte.
     *
     * Das Signal ist bewusst KNOPFLOS (siehe SignalCockpit::OHNE_WEG) — die Ursache klärt die
     * Küche, nicht das System.
     */
    public function wareneinsatzIstAbweichung(Team $team): int
    {
        $von = now()->subMonthNoOverflow()->startOfMonth();
        $bis = $von->copy()->endOfMonth();

        $a = app(WareneinsatzAbweichungService::class)->analyse($team, $von->toDateString(), $bis->toDateString());

        // Nicht belastbar heißt: kein Signal. Eine Abweichung, die nur aus fehlenden
        // Zuordnungen entsteht, wäre ein Fehlalarm über die eigene Datenlage.
        if (! $a['belastbar'] || $a['abweichung_pp'] === null) {
            return 0;
        }

        $schwelle = app(TeamSettingsService::class)->wareneinsatzAbweichungSchwellePp($team);
        if (abs($a['abweichung_pp']) < $schwelle) {
            return 0;
        }

        $periode = $von->format('Y-m');
        $mehr = $a['abweichung_pp'] > 0;

        $this->signals->erzeuge(
            $team,
            SignalTyp::WareneinsatzIstAbweichung,
            // Mehr eingekauft als nötig kostet Geld. Weniger ist auffällig, aber meist
            // Lagerabbau oder eine zu hoch angesetzte Rezeptmenge — das ist kein Alarm.
            $mehr ? SignalSeverity::Kritisch : SignalSeverity::Warnung,
            'Wareneinsatz ' . $periode . ': ' . ($mehr ? '+' : '') . number_format($a['abweichung_pp'], 1, ',', '.')
                . ' pp gegenüber der Rezeptur (' . number_format($a['abweichung_eur'], 2, ',', '.') . ' €)',
            [
                'dedup_key' => 'we-ist-abweichung:' . $periode,
                'description' => 'Eingekauft ' . number_format($a['einkauf'], 2, ',', '.') . ' €, laut Rezeptur nötig '
                    . number_format($a['theoretisch'], 2, ',', '.') . ' € bei ' . number_format($a['umsatz'], 2, ',', '.')
                    . ' € Umsatz. Ohne Inventur ist das eine Perioden-Rechnung — Lageraufbau am Monatsende sieht aus wie Schwund.',
                'payload' => $a + ['periode' => $periode, 'schwelle_pp' => $schwelle],
                'source' => 'detektor',
            ],
        );

        return 1;
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
    /**
     * R6.11 · S2 — Widerspruchs-Detektor (Wissen ⇄ Anker-Graph). Für jedes `pairing`-
     * Wissensdokument: die im `## Pairings` gelisteten Partner (KnowledgeContextService)
     * gegen die Kanten des Doc-Ankers (`pairing_anchor_edges`) — Präsenz/Absenz-Set-Diff.
     * „Doc behauptet Paarung X, Graph hat keine Kante" → EIN Signal je Doc (R&D-Frage,
     * Research-Queue), NICHT still aufgelöst. Ein Doc/Partner ohne auflösbaren Anker ist
     * eine Namens-Lücke, KEIN Widerspruch (übersprungen). Feasibility-Cut (E3): nur
     * `pairing`-Docs; Domain-Prosa-Semantik-Widersprüche = v2. Reverse-Richtung (Graph
     * hat Kante, Doc listet nicht) bewusst NICHT als Signal — bei kuratierten Teil-Listen
     * wäre das Rauschen; hier zählt die belegte Behauptung ohne Graph-Stütze.
     */
    public function widerspruchWissenGraph(Team $team, int $maxDocs = 500): int
    {
        $pairing = app(PairingService::class);
        $ctx = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class);
        $n = 0;

        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('category', 'pairing')->where('active', 1)->whereNull('deleted_at')
            ->limit($maxDocs)->get(['id', 'slug', 'title', 'content_md']);

        foreach ($docs as $doc) {
            $ankerId = $pairing->resolveByName((string) $doc->title) ?? $pairing->resolveByName((string) $doc->slug);
            if ($ankerId === null) {
                continue;   // Doc-Anker nicht auflösbar → Namens-Lücke, kein Widerspruch
            }
            $partnerNames = $ctx->extractPairingNames((string) ($doc->content_md ?? ''));
            if ($partnerNames === []) {
                continue;
            }
            $kanten = array_flip(DB::table('foodalchemist_pairing_anchor_edges')
                ->where('anchor_a_id', $ankerId)->pluck('anchor_b_id')->map(fn ($v) => (int) $v)->all());

            $fehlend = [];   // Doc behauptet Paarung, Graph kennt keine Kante
            foreach ($partnerNames as $pname) {
                $pid = $pairing->resolveByName($pname);
                if ($pid === null || $pid === $ankerId) {
                    continue;   // unauflösbarer/selbst-Partner = Namens-Lücke, kein Widerspruch
                }
                if (! isset($kanten[$pid])) {
                    $fehlend[$pid] = $pname;
                }
            }
            if ($fehlend === []) {
                continue;   // kein Widerspruch → kein Signal (es gibt schlicht keinen)
            }

            $liste = implode(', ', array_slice(array_values($fehlend), 0, 6));
            $this->signals->erzeuge(
                $team,
                SignalTyp::WiderspruchWissenGraph,
                SignalSeverity::Info,
                ($doc->title ?: $doc->slug) . ' — ' . count($fehlend) . ' im Wissen belegte Paarung(en) ohne Graph-Kante: ' . $liste,
                [
                    'dedup_key' => 'widerspruch-doc-' . $doc->id,
                    'ref_type' => 'knowledge_document',
                    'ref_id' => (int) $doc->id,
                    'description' => 'Wissensdokument (kuratiert, T0) behauptet Paarungen, die der Anker-Graph nicht kennt. '
                        . 'R&D-Frage: Kante ergänzen (belegt) oder Beleg prüfen — nicht still auflösen.',
                    'payload' => [
                        'doc_slug' => $doc->slug,
                        'anchor_id' => $ankerId,
                        'fehlende_kanten' => array_map(fn ($id, $name) => ['anchor_id' => (int) $id, 'name' => $name], array_keys($fehlend), array_values($fehlend)),
                        'doc_tier' => 'T0',       // kuratiertes Doc = belegt
                        'graph_status' => 'kante_fehlt',
                    ],
                ]
            );
            $n++;
        }

        return $n;
    }

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
