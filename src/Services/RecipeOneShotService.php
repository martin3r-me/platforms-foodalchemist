<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;

/**
 * Spec 03 L7a — der Kaskaden-Motor der One-Shot-Vollerstellung.
 *
 * Bis hierher endete jede Generierung beim geerdeten Zutaten-Gerüst samt
 * Aggregation; die Anreicherung war ein SEPARATER Klick („✨ Alles anreichern")
 * mit Review-Liste dahinter. Dieser Service verkettet beides zu einem Durchlauf.
 * Für den KI-Erstell-Knopf kann zusätzlich die Coverage-Phase laufen:
 * Step-by-step, Sensorik, Produktions-/Equipment-Felder und Prozessanker werden
 * neu synchronisiert, weil sie vom aktuellen Rezeptstand abhängen.
 *
 * Drei Entscheidungen tragen ihn:
 *
 * 1. **Keine Parallel-Implementierung.** Der Pass fährt die bestehende
 *    `BulkEnrichService`-Strecke — dieselben Prompts, derselben Vorschlags-Speicher
 *    (`foodalchemist_bulk_proposals`, damit jeder Wert auditierbar bleibt und in
 *    der Review-Queue auftaucht) und denselben Accept-Pfad `alleUebernehmen()`,
 *    in dem Override-First je Feld und die Klassen-Validierung schon stehen.
 *
 * 2. **Auto-Übernahme nur in LÜCKEN.** GL-07 verbietet Auto-Persistenz gegen
 *    menschliche Pflege — nicht das Füllen leerer Felder. Ein frisch generierter
 *    Draft hat per Konstruktion keine gepflegten Werte; `luecken()` schneidet die
 *    Schrittfolge trotzdem auf die tatsächlich leeren Ziel-Felder, damit die
 *    Kaskade (a) nichts überschreibt, was der Generator oder ein Mensch schon
 *    gesetzt hat, und (b) keinen Provider-Call für ein gefülltes Feld bezahlt.
 *    Was übrig bleibt, wird sofort übernommen — sonst wäre das Ergebnis eben
 *    nicht „voll", sondern wieder eine Aufgabenliste.
 *
 * 3. **Synchron im ohnehin asynchronen Kontext.** Aufrufer ist der separate
 *    `EnrichGeneratedRecipeJob` bzw. ein MCP-Call. Darum
 *    `verarbeiteRezept()` direkt statt `starte()`: ein dispatchter `BulkEnrichJob`
 *    liefe auf demo parallel, und `alleUebernehmen()` fände noch keine Vorschläge.
 *
 * Graceful by construction: `verarbeiteRezept()` fängt jeden Schritt-Fehler
 * einzeln (Fehl-Zeile statt Abbruch), der äußere Catch nur noch Infrastruktur.
 * Ein Provider-Ausfall mitten in der Kaskade lässt das Rezept vollständig und
 * konsistent zurück — der Kern steht, die Reste bleiben Lücken.
 *
 * L7b-2 hängt das **Kohärenz-Glied** hinten an (Spec-Kaskade: „… → Auto-Enrichment
 * → Kohärenz-Check (VK) → fertig"). Das ist bewusst die ZWEITE Achse (GL-10 §1):
 * der deterministische Aroma-Score läuft schon im Generator direkt nach dem
 * Zutaten-Sync (`RecipeGeneratorService` → `statistik['kohaerenz']`, kostet keinen
 * Call); was fehlte, ist das kulinarische Urteil `vk.kohaerenz`, das bis hierher
 * nur on-demand im VK-Detail-Panel hing. Es läuft NACH der Anreicherung, weil
 * Speisen-Klasse und Geschmacksrichtung erst dort gesetzt werden und in den
 * Teller-Kontext einfließen — und in EIGENEM try/catch, weil es von der
 * Anreicherung nicht abhängt: der Judge liest die Komponenten, die schon stehen.
 */
class RecipeOneShotService
{
    public function __construct(
        private BulkEnrichService $bulk,
        private CoherenceService $coherence,
        private SalesRecipeService $sales,
        private DarreichungService $darreichungen,
        private MargeService $marge,
        private TeamSettingsService $settings,
        private SignalDetektorService $detektor,
    ) {
    }

    /**
     * Anreicherungs-Kaskade über ein einzelnes (gerade erzeugtes) Rezept.
     *
     * Die Ebenen-Wahl fällt am `is_sales_recipe`-Flag: ein Gericht bekommt die
     * VK-Schrittfolge (Beschreibung · Wording · Plating · Speisen-Klasse), ein
     * Basisrezept die Basis-Folge (Beschreibung · Kategorie · Geschmack). Damit
     * gibt es hier keinen zweiten Ebenen-Zweig — dieselbe Regel wie im
     * Rezept-Copilot.
     *
     * @param ?float $zielVk L8b-2: angestrebter Netto-VK je Portion aus der Eingabe
     *                       (Generator-Pill / MCP). Reines Durchreich-Datum — es wird
     *                       NIRGENDS geschrieben, sondern nach der Kalkulation gegen
     *                       das Ergebnis gehalten (kein Solver, s. `zielAbgleich`).
     *
     * @return array{run_id: ?int, schritte: list<string>, uebersprungen: list<string>, uebernommen: int, offen: int, fehler: ?string, kohaerenz_urteil: ?array{score: ?int, label: ?string, schwachstelle: ?string, fehler: ?string}, wirtschaftlichkeit: ?array, coverage?: array}
     */
    public function anreichern(Team $team, FoodAlchemistRecipe $recipe, ?float $zielVk = null, bool $completeCoverage = false): array
    {
        $alle = $recipe->is_sales_recipe ? BulkEnrichService::SCHRITTE_VK : BulkEnrichService::SCHRITTE;
        $schritte = $this->bulk->luecken($recipe, $alle);

        $ergebnis = [
            'run_id' => null,
            'schritte' => $schritte,
            'uebersprungen' => array_values(array_diff($alle, $schritte)),
            'uebernommen' => 0,
            'offen' => 0,
            'fehler' => null,
            'kohaerenz_urteil' => null,
            'wirtschaftlichkeit' => null,
            'coverage' => null,
        ];

        if ($schritte !== []) {                                // leer ⇒ nichts zu füllen, kein Lauf, kein Call
            try {
                // Lauf-Zeile = Fortschritts-Anker (die UI pollt sie über `status()`)
                // UND Audit-Spur: die Vorschläge bleiben in der Review-Queue sichtbar,
                // auch wenn sie in derselben Sekunde übernommen wurden.
                $runId = $this->bulk->laufAnlegen(
                    $team,
                    1,
                    $recipe->is_sales_recipe ? BulkRunType::EnrichVk : BulkRunType::Enrich,
                    // V-047: der One-Shot ist der einzige Anreicherungs-Pfad, der seine
                    // Schrittfolge erst zur Laufzeit aus den Lücken schneidet — ohne
                    // Kontext bliebe unauffindbar, warum dieser Lauf drei Schritte fuhr
                    // und der nächste am selben Rezept keinen.
                    ['schritte' => array_values($schritte), 'quelle' => 'one_shot', 'recipe_id' => (int) $recipe->id],
                );
                $ergebnis['run_id'] = $runId;

                $this->bulk->verarbeiteRezept($team, $runId, $recipe->id, $schritte);
                $ergebnis['uebernommen'] = $this->bulk->alleUebernehmen($team, $runId);
                $ergebnis['offen'] = $this->bulk->offeneVorschlaege($team, $runId);
            } catch (\Throwable $e) {
                // Nie werfen: das Rezept ist zu diesem Zeitpunkt fertig angelegt und
                // aggregiert. Ein gescheiterter Anreicherungs-Pass ist eine Lücke,
                // kein Grund, die Generierung als Fehler zu melden.
                $ergebnis['fehler'] = mb_strimwidth($e->getMessage(), 0, 300);
            }
        }

        $ergebnis['kohaerenz_urteil'] = $this->kohaerenzGlied($team, $recipe);
        $ergebnis['wirtschaftlichkeit'] = $this->wirtschaftlichkeitsGlied($team, $recipe, $zielVk);
        if ($completeCoverage) {
            $ergebnis['coverage'] = $this->coverageGlieder($team, $recipe->fresh() ?? $recipe);
        }

        return $ergebnis;
    }

    /**
     * Vollerstellungs-Coverage fuer den KI-Knopf: Bausteine neu synchronisieren,
     * die im Editor eigene KI-Aktionen haben. Anders als Einzel-KI-Knoepfe ist
     * "voll anreichern" eine bewusste Aktualisierung nach Rezeptaenderungen.
     *
     * @return array<string, array{status: string, fehler?: string}>
     */
    private function coverageGlieder(Team $team, FoodAlchemistRecipe $recipe): array
    {
        $fertigung = $this->fertigungsGlied($recipe);
        $eigenschaften = $this->eigenschaftenGlied($recipe);
        $equipment = $this->equipmentGlied($team, $recipe->fresh() ?? $recipe);
        $posten = $this->postenGlied($team, $recipe->fresh() ?? $recipe);
        $steps = $this->stepGlied($recipe->fresh() ?? $recipe);
        $prozessanker = $this->prozessankerGlied($recipe->fresh() ?? $recipe);
        $sensorik = $this->sensorikGlied($recipe->fresh() ?? $recipe);

        return [
            'fertigung' => $fertigung,
            'eigenschaften' => $eigenschaften,
            'equipment' => $equipment,
            'posten' => $posten,
            'steps' => $steps,
            'prozessanker' => $prozessanker,
            'sensorik' => $sensorik,
        ];
    }

    /** @return array{status: string, production_depth?: ?string, fehler?: string} */
    private function fertigungsGlied(FoodAlchemistRecipe $recipe): array
    {
        try {
            $vorschlag = app(Ai\AiGatewayService::class)->propose('recipe.production_depth', [
                'name' => $recipe->name,
                'production_depth' => $recipe->production_depth,
                'zutaten' => $recipe->ingredients()->whereNull('deleted_at')->pluck('raw_text')->take(30)->all(),
            ], [
                'target_table' => 'foodalchemist_recipes',
                'target_id' => $recipe->id,
            ]);

            $wert = $vorschlag->werte['production_depth'] ?? null;
            if (! in_array($wert, ['from_scratch', 'teilfertig', 'convenience'], true)) {
                return ['status' => 'leer', 'production_depth' => $recipe->production_depth];
            }

            $recipe->forceFill(['production_depth' => $wert])->save();

            return ['status' => 'aktualisiert', 'production_depth' => $wert];
        } catch (\Throwable $e) {
            return ['status' => 'fehler', 'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
        }
    }

    /** @return array{status: string, work_time_min?: ?int, temperature?: ?string, function?: ?string, fehler?: string} */
    private function eigenschaftenGlied(FoodAlchemistRecipe $recipe): array
    {
        try {
            $vorschlag = app(Ai\AiGatewayService::class)->propose('recipe.eigenschaften', [
                'name' => $recipe->name,
                'haltbarkeit_tage' => null,
                'regenerierbarkeit' => null,
                'transportstabilitaet' => null,
                'work_time_min' => $recipe->work_time_min,
                'temperature' => $recipe->temperature,
                'function' => $recipe->function,
                'preparation' => $recipe->preparation,
                'zutaten' => $recipe->ingredients()->whereNull('deleted_at')->pluck('raw_text')->take(30)->all(),
            ], [
                'target_table' => 'foodalchemist_recipes',
                'target_id' => $recipe->id,
            ]);

            $update = [];
            if (isset($vorschlag->werte['work_time_min']) && is_numeric($vorschlag->werte['work_time_min'])) {
                $update['work_time_min'] = max(0, (int) $vorschlag->werte['work_time_min']);
            }
            foreach (['temperature', 'function'] as $feld) {
                $wert = $vorschlag->werte[$feld] ?? null;
                if (is_string($wert) && trim($wert) !== '') {
                    $update[$feld] = trim($wert);
                }
            }

            if ($update === []) {
                return ['status' => 'leer'];
            }

            $recipe->forceFill($update)->save();

            return [
                'status' => 'aktualisiert',
                'work_time_min' => isset($update['work_time_min']) ? (int) $update['work_time_min'] : $recipe->work_time_min,
                'temperature' => $update['temperature'] ?? $recipe->temperature,
                'function' => $update['function'] ?? $recipe->function,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'fehler', 'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
        }
    }

    /** @return array{status: string, n_equipment?: int, fehler?: string} */
    private function equipmentGlied(Team $team, FoodAlchemistRecipe $recipe): array
    {
        try {
            $vokabular = FoodAlchemistVocabKochequipment::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->pluck('slug')->all();

            $vorschlag = app(Ai\AiGatewayService::class)->propose('recipe.equipment', [
                'name' => $recipe->name,
                'equipment_slugs' => $recipe->equipment()->pluck('slug')->all(),
                'vokabular' => $vokabular,
                'preparation' => $recipe->preparation,
                'zutaten' => $recipe->ingredients()->whereNull('deleted_at')->pluck('raw_text')->take(30)->all(),
            ], [
                'target_table' => 'foodalchemist_recipe_equipment',
                'target_id' => $recipe->id,
            ]);

            $slugs = array_values(array_filter((array) ($vorschlag->werte['equipment_slugs'] ?? []), 'is_string'));
            $ids = $slugs === [] ? collect() : FoodAlchemistVocabKochequipment::visibleToTeam($team)
                ->whereIn('slug', $slugs)->pluck('id');
            $recipe->equipment()->sync($ids->all());

            return ['status' => $ids->isEmpty() ? 'leer' : 'aktualisiert', 'n_equipment' => $ids->count()];
        } catch (\Throwable $e) {
            return ['status' => 'fehler', 'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
        }
    }

    /** @return array{status: string, station_id?: ?int, station?: ?string, fehler?: string} */
    private function postenGlied(Team $team, FoodAlchemistRecipe $recipe): array
    {
        try {
            $station = $this->stationVorschlag($team, $recipe);
            if ($station === null) {
                return ['status' => 'offen'];
            }

            $update = ['default_station_id' => $station->id];
            if ($recipe->batch_max_kg === null && $station->batch_max_kg !== null) {
                $update['batch_max_kg'] = $station->batch_max_kg;
            }
            if ($recipe->batch_max_pieces === null && $station->batch_max_pieces !== null) {
                $update['batch_max_pieces'] = $station->batch_max_pieces;
            }
            $recipe->forceFill($update)->save();

            return ['status' => 'aktualisiert', 'station_id' => (int) $station->id, 'station' => $station->name];
        } catch (\Throwable $e) {
            return ['status' => 'fehler', 'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
        }
    }

    private function stationVorschlag(Team $team, FoodAlchemistRecipe $recipe): ?FoodAlchemistProductionStation
    {
        $stations = FoodAlchemistProductionStation::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get();
        if ($stations->isEmpty()) {
            return null;
        }

        $text = mb_strtolower(implode(' ', array_filter([
            $recipe->name,
            $recipe->function,
            $recipe->temperature,
            $recipe->production_depth,
            $recipe->preparation,
            implode(' ', $recipe->equipment()->pluck('slug')->all()),
            implode(' ', $recipe->equipment()->pluck('name')->all()),
        ])));

        $best = null;
        $bestScore = 0;
        foreach ($stations as $station) {
            $tokens = preg_split('/[^a-z0-9äöüß]+/iu', mb_strtolower($station->slug . ' ' . $station->name . ' ' . ($station->group_name ?? ''))) ?: [];
            $tokens = array_values(array_unique(array_filter($tokens, fn ($t) => mb_strlen($t) >= 4)));
            $score = 0;
            foreach ($tokens as $token) {
                if (str_contains($text, $token)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $best = $station;
                $bestScore = $score;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /** @return array{status: string, matched?: list<string>, added?: list<string>, removed?: list<string>, fehler?: string} */
    private function prozessankerGlied(FoodAlchemistRecipe $recipe): array
    {
        try {
            $r = app(ProcessAnchorService::class)->groundRecipe($recipe, true);

            return [
                'status' => ($r['matched'] ?? []) === [] ? 'leer' : 'aktualisiert',
                'matched' => $r['matched'] ?? [],
                'added' => $r['added'] ?? [],
                'removed' => $r['removed'] ?? [],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'fehler', 'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
        }
    }

    /** @return array{status: string, n_steps?: int, fehler?: string} */
    private function stepGlied(FoodAlchemistRecipe $recipe): array
    {
        $bestehend = FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)->count();

        try {
            $vorschlag = app(Ai\AiGatewayService::class)->propose('recipe.steps', [
                'name' => $recipe->name,
                'zutaten' => $recipe->ingredients()->whereNull('deleted_at')->pluck('raw_text')->take(30)->all(),
                'schritte_bestand' => FoodAlchemistRecipeStep::where('recipe_id', $recipe->id)
                    ->orderBy('position')->orderBy('id')->get(['phase', 'text'])->map(fn ($s) => [
                        'phase' => $s->phase,
                        'text' => $s->text,
                    ])->all(),
                'modus' => $bestehend > 0 ? 'voll_anreichern_ueberschreiben' : 'voll_anreichern_erstellen',
            ], [
                'target_table' => 'foodalchemist_recipe_steps',
                'target_id' => $recipe->id,
                'structural_retry' => fn (array $p) => is_array($p['werte']['steps'] ?? null) && $p['werte']['steps'] !== [],
            ]);

            $steps = [];
            foreach ($vorschlag->werte['steps'] ?? [] as $s) {
                $text = trim((string) ($s['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $phase = trim((string) ($s['phase'] ?? ''));
                $steps[] = ['phase' => $phase !== '' ? $phase : null, 'text' => $text];
            }

            if ($steps === []) {
                return ['status' => 'leer', 'n_steps' => 0];
            }

            app(RecipeStepService::class)->sync($recipe, $steps);
            $recipe->update([
                'preparation_source' => 'ki',
                'preparation_ai_confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            ]);

            return ['status' => $bestehend > 0 ? 'aktualisiert' : 'erstellt', 'n_steps' => count($steps)];
        } catch (\Throwable $e) {
            return ['status' => 'fehler', 'n_steps' => 0, 'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
        }
    }

    /** @return array{status: string, fehler?: string} */
    private function sensorikGlied(FoodAlchemistRecipe $recipe): array
    {
        $recipe = $recipe->fresh() ?? $recipe;
        if (trim((string) $recipe->preparation) === '') {
            return ['status' => 'uebersprungen_ohne_zubereitung'];
        }
        if ((int) $recipe->ingredients()->whereNull('deleted_at')->count() === 0) {
            return ['status' => 'uebersprungen_ohne_zutaten'];
        }

        try {
            return app(SensorikService::class)->bewerteRezept((int) $recipe->id, true);
        } catch (\Throwable $e) {
            return ['status' => 'fehler', 'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
        }
    }

    /**
     * L7b-2 · Kohärenz-Glied: kulinarisches Urteil über den fertigen Teller.
     *
     * Zwei Gates, beide inhaltlich:
     *
     * 1. **Nur VK.** `vk.kohaerenz` beurteilt einen Teller („hält das zusammen?").
     *    Ein Basisrezept IST eine Komponente — es gibt keinen Teller zu beurteilen.
     * 2. **Erst ab zwei Komponenten.** Kohärenz ist eine Aussage über Zusammenspiel;
     *    bei einer einzigen Zeile gibt es keines. Der Call wäre bezahltes Rauschen,
     *    und ein 100er-Score auf einem Ein-Zeiler wäre eine Falschaussage.
     *
     * Nie werfen: `judge()` wirft absichtlich, wenn der Provider keinen `score`
     * liefert (FakeProvider-Grenze — dann wird bewusst nichts gecacht). Hier wird
     * das zu einer Fehl-Zeile am Ergebnis. Ein fehlendes Urteil ist eine Lücke am
     * fertigen Rezept, kein Grund, die Generierung als gescheitert zu melden.
     *
     * @return array{score: ?int, label: ?string, schwachstelle: ?string, fehler: ?string}|null
     */
    private function kohaerenzGlied(Team $team, FoodAlchemistRecipe $recipe): ?array
    {
        if (! $recipe->is_sales_recipe) {
            return null;
        }
        if ($recipe->ingredients()->count() < 2) {
            return null;
        }

        try {
            $zeile = $this->coherence->judge($team, $recipe->id);

            return [
                'score' => $zeile->score,
                'label' => $zeile->label,
                'schwachstelle' => $zeile->schwachstelle,
                'fehler' => null,
            ];
        } catch (\Throwable $e) {
            return ['score' => null, 'label' => null, 'schwachstelle' => null, 'fehler' => mb_strimwidth($e->getMessage(), 0, 300)];
        }
    }

    /**
     * L8 · Wirtschaftlichkeits-Glied: das per KI erstellte Gericht endet BEPREIST.
     *
     * Bis hierher endete die Kaskade beim kulinarischen Urteil — wirtschaftlich war
     * das Erzeugnis leer: keine Portionsgröße, oft keine Aufschlagsklasse, keine
     * Standard-Darreichung, also auch kein VK und keine Marge. Das Glied schließt
     * genau diese drei Vorbedingungen und lässt danach die BESTEHENDE Preis-Maschine
     * rechnen. Vier Entscheidungen tragen es:
     *
     * 1. **Kein zweiter Schreibweg.** Portion und Aufschlagsklasse gehen durch
     *    `SalesRecipeService::updateVk` — dort hängt die Selbstheilung der
     *    Standard-Darreichung schon dran (`syncStandardDarreichung`), und die
     *    Darreichung ist die Preis-Wahrheit. `ensureStandard` danach ist nur noch
     *    der Fall „nichts zu ändern, aber auch keine Darreichung da".
     *    Der VK selbst wird NIE geschrieben: `price_mode` bleibt `auto`, der Preis
     *    entsteht in `DarreichungService::recomputePreise` aus EK × Aufschlag und
     *    ist damit jederzeit überschreibbar (DoD „kein Auto-Publish von Preisen";
     *    die R2.5-Trennung Live-Marge ↔ freigegebener Snapshot bleibt unberührt).
     *
     * 2. **Nur Lücken füllen** (wie der Anreicherungs-Pass, GL-07) — und die Portion
     *    NICHT raten. Die Aufschlagsklasse hat einen belastbaren Default
     *    (Klasse-vor-HG, gepflegte Stammdaten); die Portionsgröße hat keinen. Die
     *    naheliegende Ableitung `yield_kg × 1000 / sales_unit_count` (so zeigt
     *    `SalesRecipeService::cockpit` sie an) ist hier falsch: die Darreichung
     *    rechnet `ek_portion = EK/g × Grammatur × Anzahl`, multipliziert also mit
     *    derselben Anzahl wieder hoch — der VK wäre der Chargenpreis. Die zwei
     *    Lesarten von `sales_unit_count` (Einheiten je Verkauf ↔ Portionen je
     *    Charge) sind ein Bestands-Widerspruch, kein Beifahrer-Fix → V-041.
     *    Fehlt die Portion, ist sie eine benannte Lücke; sie soll aus dem
     *    Generator-Vorschlag kommen (Spec-DoD „Portionsgröße verbindlich").
     *
     *    Aus demselben Grund kommt der Wareneinsatz aus der **Darreichung**
     *    (`ek_portion` gegen `sales_net` — dieselbe Menge auf beiden Seiten) und
     *    nicht aus `KalkulationService::recipeHk`, das den Chargen-EK durch
     *    `sales_unit_count` teilt und gegen den Portionspreis hält.
     *
     * 3. **Am Ende der Kaskade, nicht im Generator.** Die Speisen-Klasse entsteht
     *    erst im Anreicherungs-Pass; erst danach kann der Klasse-vor-HG-Default
     *    überhaupt greifen. Ein Glied im Generator hätte die Hälfte der Gerichte
     *    ohne Aufschlagsklasse gelassen.
     *
     * 4. **Eine Ampel-Regel, nicht zwei.** Schwelle und Signal sind dieselben wie
     *    im Scheduler-Detektor (`wareneinsatzUeberZielFuer`, R2.1-Muster): über Ziel
     *    = gelb/Warnung, über 1,5 × Ziel = rot/kritisch. Die Foodbook-Ampel arbeitet
     *    mit `food_cost_tolerance_pp` — das ist ein Buch-Feld, am Gericht gibt es
     *    kein Gegenstück, und eine zweite Schwelle hieße: das Generator-Ergebnis
     *    sagt „grün", das Signal-Cockpit sagt „Warnung".
     *
     * 5. **L8b-2: der Ziel-VK wird gehalten, nicht durchgesetzt.** Gibt der Mensch
     *    „Gericht für 8,50 €" vor, geht das als Vorgabe in den Prompt (dort steuert
     *    es Komponenten-Wahl und Grammatur) — der gerechnete VK bleibt danach
     *    unangetastet. Die Alternative wäre, den Preis auf das Ziel zu setzen; dann
     *    stimmte die Zahl und die Marge wäre unsichtbar falsch. Stattdessen wird die
     *    Frage beantwortet, die wirklich zählt: *was würde der Zielpreis bedeuten?*
     *    → derselbe Wareneinsatz-Bruch, nur mit dem Ziel-VK im Nenner, beurteilt von
     *    DERSELBEN Ampel-Leiter (Entscheidung 4). Damit gibt es hier keine eigene
     *    Toleranz-Schwelle: „Ziel erreichbar" heißt nichts anderes als „bei diesem
     *    Preis liegt der Wareneinsatz noch im Ziel". Das ist die Brücke zur
     *    R2.4-Solver-Denke — bewusst ohne Solver.
     *
     * Nie werfen — ein Rezept ohne Preis ist eine Lücke, kein gescheiterter Lauf.
     *
     * @return array{sales_net: ?float, ek_total_eur: ?float, ek_pro_portion: ?float,
     *               wareneinsatz_pct: ?float, ziel_pct: float, ampel: string, portion_g: ?float,
     *               aufschlagsklasse: ?string, vorlaeufig: bool, luecken: list<string>,
     *               signal: bool, ziel_vk: ?float, ziel_delta_eur: ?float,
     *               ziel_wareneinsatz_pct: ?float, ziel_ampel: string, fehler: ?string}|null
     */
    private function wirtschaftlichkeitsGlied(Team $team, FoodAlchemistRecipe $recipe, ?float $zielVk = null): ?array
    {
        if (! $recipe->is_sales_recipe) {
            return null;                                          // Basisrezept hat keinen VK
        }

        $ziel = $this->settings->zielWareneinsatzPct($team);
        // Nicht-positive Vorgaben (0, negativ) sind keine Vorgabe — die Bänder stehen
        // an den Eingängen (Modal/MCP), hier nur der Schutz gegen Unsinn im Nenner.
        $zielVk = $zielVk !== null && $zielVk > 0 ? round($zielVk, 2) : null;
        $leer = [
            'sales_net' => null, 'ek_total_eur' => null, 'ek_pro_portion' => null,
            'wareneinsatz_pct' => null, 'ziel_pct' => $ziel, 'ampel' => 'unbekannt',
            'portion_g' => null, 'aufschlagsklasse' => null, 'vorlaeufig' => false,
            'luecken' => [], 'signal' => false, 'ziel_vk' => $zielVk,
            'ziel_delta_eur' => null, 'ziel_wareneinsatz_pct' => null, 'ziel_ampel' => 'unbekannt',
            'fehler' => null,
        ];

        try {
            $recipe = $recipe->fresh() ?? $recipe;                // Klasse/HG kommen aus dem Pass davor

            // ── 1. Portion: gesetzt oder Lücke (nie geraten, s. Entscheidung 2) ──
            $portion = $recipe->sales_quantity_per_unit_g !== null ? (float) $recipe->sales_quantity_per_unit_g : null;

            // ── 2. Aufschlagsklasse: gesetzt > Klasse-Default > HG-Default > Lücke ──
            $ak = $recipe->markup_class_id
                ?? $recipe->dishClass?->default_markup_class_id
                ?? $recipe->dishMainGroup?->default_markup_class_id;

            $update = [];
            if ($recipe->markup_class_id === null && $ak !== null) {
                $update['markup_class_id'] = $ak;
            }
            if ($update !== []) {
                $this->sales->updateVk($team, $recipe->id, $update);
                // `updateVk` stempelt `last_modified_by='vk_editor'` — das ist die
                // Provenienz der Editor-Fläche. Hier hat kein Mensch editiert, also
                // zurück auf die Provenienz des Laufs (roh, damit `updated_at` steht).
                \Illuminate\Support\Facades\DB::table('foodalchemist_recipes')
                    ->where('id', $recipe->id)->update(['last_modified_by' => 'vk_generator']);
            }

            // ── 3. Standard-Darreichung (idempotent; rechnet EK/VK beim Anlegen mit) ──
            $standard = $this->darreichungen->ensureStandard($team, $recipe->id, 'one_shot');

            // ── 4. Zahlen + Ampel aus der Preis-Wahrheit: der Darreichung ──
            $recipe = $recipe->fresh() ?? $recipe;
            $standard = $standard?->refresh();
            $vk = $standard?->sales_net !== null ? (float) $standard->sales_net : null;
            $ekPortion = $standard?->ek_portion !== null ? (float) $standard->ek_portion : null;
            $we = $this->marge->marge($vk, $ekPortion)['wareneinsatz_pct'] ?? null;

            $luecken = [];
            if ($portion === null) {
                $luecken[] = 'portion';                           // ohne sie kein Auto-VK
            }
            if ($ak === null) {
                $luecken[] = 'aufschlagsklasse';
            }
            // L8b: die dritte Vorbedingung war bis hierher stumm. `ensureStandard`
            // gibt bewusst `null` zurück, wenn es nichts raten darf (Varianten ohne
            // Standard-Flag) oder das Servierform-Vokabular `unbestimmt` fehlt —
            // dann gibt es keine Preis-Zeile und damit keinen VK. Ohne diese Lücke
            // zeigte die Fläche in dem Fall GAR NICHTS: kein Preis, kein Grund.
            if ($standard === null) {
                $luecken[] = 'darreichung';
            }

            $gesamt = (int) ($recipe->ek_n_ingredients_total ?? 0);
            $bepreist = (int) ($recipe->ek_n_ingredients_priced ?? 0);

            // Signal mit DEMSELBEN Wert, den die Ampel zeigt (Übergabe statt
            // Zweitrechnung) — sonst sagt das Generator-Ergebnis „gelb" und das
            // Cockpit rechnet sich seine eigene Quote dazu.
            $signal = $we !== null && $we > $ziel
                && $this->detektor->wareneinsatzUeberZielFuer($team, $recipe, $ziel, $we);

            // ── 5. L8b-2: Ist gegen Ziel — was WÜRDE der Zielpreis bedeuten? ──
            // Der Wareneinsatz am Ziel-VK braucht nur den EK je Portion, nicht den
            // gerechneten VK: er steht auch dann, wenn der Ist-Preis noch an einer
            // Lücke hängt (fehlende Aufschlagsklasse). Genau dann ist er am
            // nützlichsten — er sagt, ob das Ziel überhaupt tragfähig ist.
            $zielWe = $zielVk !== null ? ($this->marge->marge($zielVk, $ekPortion)['wareneinsatz_pct'] ?? null) : null;

            return [
                'sales_net' => $vk,
                'ek_total_eur' => $recipe->ek_total_eur !== null ? (float) $recipe->ek_total_eur : null,
                'ek_pro_portion' => $ekPortion !== null ? round($ekPortion, 2) : null,
                'wareneinsatz_pct' => $we,
                'ziel_pct' => $ziel,
                'ampel' => $this->weAmpel($we, $ziel),
                'portion_g' => $portion,
                'aufschlagsklasse' => $recipe->markupClass?->code,
                // #511-F2: Park-GPs ohne Preis machen den EK unvollständig — der VK
                // daraus ist vorläufig und muss es auch heißen.
                'vorlaeufig' => $gesamt > 0 && $bepreist < $gesamt,
                'luecken' => $luecken,
                'signal' => $signal,
                // L8b-2: Vorgabe, Abstand und Folge — drei Zahlen, keine Wertung
                // darüber hinaus. `ziel_delta_eur` ist Ist − Ziel: positiv = das
                // Gericht ist zum Zielpreis (noch) nicht kalkulierbar.
                'ziel_vk' => $zielVk,
                'ziel_delta_eur' => $zielVk !== null && $vk !== null ? round($vk - $zielVk, 2) : null,
                'ziel_wareneinsatz_pct' => $zielWe,
                'ziel_ampel' => $this->weAmpel($zielWe, $ziel),
                'fehler' => null,
            ];
        } catch (\Throwable $e) {
            return ['fehler' => mb_strimwidth($e->getMessage(), 0, 300)] + $leer;
        }
    }

    /**
     * Die Leiter liegt jetzt im MargeService — EINE Wahrheit für OneShot, Signale und den
     * VK-Editor-KPI-Streifen (Spec 28 §6.1). Hier nur noch delegiert, damit die Aufrufe
     * oben unverändert lesbar bleiben.
     */
    private function weAmpel(?float $we, float $ziel): string
    {
        return app(MargeService::class)->weAmpel($we, $ziel);
    }
}
