<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use RuntimeException;

/**
 * R6.1 — Brief → fertiges Konzept mit Kohäsions-Beweis.
 *
 * Kern-Invariante: Das Konzept wird AUSSCHLIESSLICH aus echten VK-Gerichten des
 * Teams gebaut (keine Halluzinations-Gerichte) — ein Slot ohne passenden Treffer
 * bleibt LEER mit Begründung (slot.note + Protokoll), nie erfunden befüllt.
 *
 * Pipeline: Planungs-Gerüst (R4.1) → deterministischer Assembler (harte Filter aus
 * den Gerüst-Regeln: No-Gos/Allergene/Preisrahmen; Diät-Quoten zuerst; Ranking über
 * den Pairing-Graphen = Kanten-Gewinn gegen die schon gewählte Menüfolge) →
 * Draft-Konzept + Gerüst-Kopie am Konzept → Kohäsions-Beweis (menuCohesion) +
 * R4.2-Coverage laufen automatisch (dieselbe Messlatte wie für Menschen).
 *
 * Freitext-Brief: KI (AiGateway, prompt `concept.brief_geruest`) übersetzt den
 * Brief in ein Gerüst — die KI wählt also den RAHMEN, die Gericht-Auswahl selbst
 * bleibt deterministisch graph-gerankt („Keine Erfindungen").
 */
class ConceptGeneratorService
{
    public function __construct(
        private PlanningFrameService $frames,
        private CoverageService $coverage,
        private PairingService $pairing,
        private ConceptService $concepts,
        private MenuCandidatePoolService $pool,
        private MenuAssemblyService $assembly,
    ) {}

    // ── Hauptpfad: Gerüst → Konzept ────────────────────────────────────

    /**
     * @return array{concept: FoodAlchemistConcept, protokoll: list<array>, kohaesion: array, coverage: array}
     */
    public function generiereAusGeruest(Team $team, FoodAlchemistPlanningFrame $frame, ?string $name = null, string $via = 'ui'): array
    {
        $frame->loadMissing(['slots.rules', 'rules']);
        if ($frame->slots->isEmpty()) {
            throw new RuntimeException('Gerüst hat keine Slots — erst Dramaturgie/Mengengerüst pflegen (oder Brief-Pfad nutzen).');
        }

        $concept = $this->concepts->create($team, [
            'name' => $name !== null && trim($name) !== '' ? trim($name) : 'Konzept-Entwurf aus Gerüst',
            'status' => 'draft',
        ]);
        $concept->update(['created_via' => 'concept_generator_' . $via]);

        // Gerüst ans Konzept kopieren (eigene Kopie) — der Coverage-Check misst dann direkt am Konzept
        $this->frames->kopiereZu($team, $frame, 'concept', $concept->id, 'concept_generator');

        return $this->fuelleBestehendesKonzept($team, $concept, $frame);
    }

    // ── 12·S2b: Übernahme der marge-optimalen Assemblierung ────────────

    /**
     * 12·S2b (R2.4) — die **explizite** Übernahme einer Assemblierung als Draft-Konzept.
     *
     * Der marge-optimale Zwilling von `generiereAusGeruest`: gleicher Rahmen, gleiche
     * Schreibwege (`ConceptService::addSlot`/`fillSlot`), nur wählt hier
     * `MenuAssemblyService` statt des greedy Assemblers. Vier tragende Entscheidungen:
     *
     * 1. **Geschrieben wird das Solver-Ergebnis, nicht eine zweite Auswahl.** Die Slots
     *    entstehen aus `assemblierung['slots']` — dieselbe Liste, die die Vorschau zeigt.
     *    Ein „Nachwählen" beim Schreiben wäre eine zweite Auswahl-Wahrheit.
     * 2. **Der Gegenzeichnungs-Riegel** (`$erwartetesDbPp`): stimmt das frisch gerechnete
     *    DB p. P. nicht mit dem der Vorschau, hat sich der Bestand zwischen Ansicht und
     *    Klick bewegt (Preis, neues Gericht, geänderte Regel) → Abbruch statt stiller
     *    Übernahme eines anderen Menüs. Optional, weil ein Erstaufruf noch keine
     *    Vorschau-Zahl hat.
     * 3. **Nie in ein befülltes Konzept hinein.** Ein Ziel-Konzept mit Slots wird
     *    abgelehnt (GL 5: nichts überschreiben, was der Lauf nicht selbst angelegt hat) —
     *    aufräumen ist eine menschliche Entscheidung, keine Nebenwirkung der Übernahme.
     * 4. **Ein fremdes Gerüst wird nicht angetastet.** Hat das Ziel-Konzept schon ein
     *    eigenes Gerüst, bleibt es stehen (Coverage misst weiter dagegen); nur ein
     *    Konzept *ohne* Gerüst bekommt die Kopie, damit die Messlatte überhaupt existiert.
     *
     * @param  ?int  $conceptId  null = neues Draft-Konzept anlegen
     * @param  ?float  $erwartetesDbPp  DB p. P. aus der Vorschau (optimistischer Riegel)
     * @return array{concept: FoodAlchemistConcept, assemblierung: array, protokoll: list<array>, kohaesion: array, coverage: array}
     */
    public function uebernehmeAssemblierung(
        Team $team,
        FoodAlchemistPlanningFrame $frame,
        ?int $conceptId = null,
        ?string $name = null,
        ?int $gaeste = null,
        ?float $erwartetesDbPp = null,
        string $via = 'ui'
    ): array {
        $assemblierung = $this->assembly->assembliere($team, $frame, $gaeste);
        $dbPp = (float) $assemblierung['zielfunktion']['db_pp'];

        if ($erwartetesDbPp !== null && abs($dbPp - $erwartetesDbPp) > 0.01) {
            throw new RuntimeException(
                'Vorschau veraltet: die Assemblierung liefert jetzt ' . number_format($dbPp, 2, ',', '.')
                . ' € DB p. P. statt der erwarteten ' . number_format($erwartetesDbPp, 2, ',', '.')
                . ' € — der Bestand hat sich bewegt. Erst neu ansehen, dann übernehmen.'
            );
        }

        if ($conceptId === null) {
            $concept = $this->concepts->create($team, [
                'name' => $name !== null && trim($name) !== '' ? trim($name) : 'Konzept-Entwurf (marge-optimal)',
                'status' => 'draft',
            ]);
            $concept->update(['created_via' => 'menu_assembly_' . $via]);
        } else {
            $concept = FoodAlchemistConcept::visibleToTeam($team)->find($conceptId);
            if ($concept === null) {
                throw new RuntimeException('Ziel-Konzept nicht sichtbar/vorhanden.');
            }
            if ((string) $concept->status !== 'draft') {
                throw new RuntimeException("Ziel-Konzept hat Status „{$concept->status}“ — die Übernahme schreibt nur in Entwürfe.");
            }
            $vorhandene = $concept->slots()->count();
            if ($vorhandene > 0) {
                throw new RuntimeException(
                    "Ziel-Konzept hat schon {$vorhandene} Position(en) — die Übernahme überschreibt nichts. "
                    . 'Entweder leeres Konzept angeben oder ohne concept_id ein neues anlegen lassen.'
                );
            }
        }

        // Gerüst-Kopie nur, wenn das Konzept noch keine eigene Messlatte hat (Punkt 4).
        if ($this->frames->find('concept', $concept->id) === null) {
            $this->frames->kopiereZu($team, $frame, 'concept', $concept->id, 'menu_assembly');
        }

        $protokoll = [];
        $recipeIds = [];
        foreach ($assemblierung['slots'] as $zeile) {
            if ($zeile['gerichte'] === []) {
                $leer = $this->concepts->addSlot($team, $concept->id, ['role' => $zeile['label']]);
                $this->concepts->updateSlot($team, $leer->id, ['note' => $zeile['begruendung']]);
            }
            foreach ($zeile['gerichte'] as $gericht) {
                $slot = $this->concepts->addSlot($team, $concept->id, ['role' => $zeile['label']]);
                $this->concepts->fillSlot($team, $slot->id, ['sales_recipe_id' => $gericht['id'], 'type' => 'gericht']);
                $recipeIds[$gericht['id']] = true;
            }
            $protokoll[] = [
                'slot' => $zeile['label'],
                'status' => $zeile['status'],
                'begruendung' => $zeile['begruendung'],
                'gerichte' => array_map(fn (array $g) => [
                    'id' => $g['id'], 'name' => $g['name'], 'diet_form' => $g['diet_form'], 'sales_net' => $g['sales_net'],
                ], $zeile['gerichte']),
            ];
        }

        $dishes = FoodAlchemistRecipe::whereIn('id', array_keys($recipeIds))->get()->all();

        return [
            'concept' => $concept->refresh(),
            'assemblierung' => $assemblierung,
            'protokoll' => $protokoll,
            'kohaesion' => $this->pairing->menuCohesion($dishes),
            'coverage' => $this->coverage->coverage($team, 'concept', $concept->id),
        ];
    }

    // ── Brief-Pfad: Freitext → Gerüst (KI) → Konzept ───────────────────

    /**
     * Freitext-Brief → KI baut das Planungs-Gerüst (Rahmen), dann läuft der
     * deterministische Assembler. Gerüst + Konzept entstehen beide als Draft.
     */
    /**
     * @param array<string,mixed> $menueAchsen Concept-Tab Menü-Leitplanken (kanonische Keys aus
     *   reglerParams: menue_preis_{min,ziel,max}_pp, menue_gaenge, menue_quote_{vegan,vegetarisch}_pct,
     *   menue_balance …). Konsumiert: Preis-Korridor je Person (überschreibt den KI-Gerüst-Kopf
     *   autoritativ), Anzahl Gänge (deckelt die gang-Slots), Diät-Quoten (frame-diet_quota-Rules) und
     *   die Portfolio-Balance (Zusammenstellungs-Direktive an den Gerüst-Prompt). Start-Tab-Leitplanken
     *   propagieren die Kaskade.
     */
    public function generiereAusBrief(Team $team, string $brief, ?string $name = null, string $via = 'ui', bool $useFavoritesList = false, bool $favoritesConvenienceOnly = false, array $menueAchsen = []): array
    {
        $brief = trim($brief);
        if ($brief === '') {
            throw new RuntimeException('Leerer Brief — Freitext oder Gerüst nötig.');
        }

        $kontext = [
            'brief' => $brief,
            'diaet_vokabular' => \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule::DIET_FORMS,
            'allergen_keys' => FoodAlchemistGp::ALLERGEN_FIELDS,
        ];
        // 06·H3: opt-in Favoriten (Default aus → byte-identisch); H4b: optional nur Convenience-Favoriten
        if ($useFavoritesList) {
            $fav = $this->favoritesHint($team, $favoritesConvenienceOnly);
            if ($fav !== null) {
                $kontext['favorites'] = $fav;
            }
        }
        // Menü-Leitplanke »Portfolio-Balance« (menue_balance, Concept-Tab): WEICHE Zusammenstellungs-
        // Direktive an den Gerüst-Prompt — wie breit das Menü über Proteine/Warengruppen/Garmethoden
        // streut (ausgewogen ↔ fokussiert). Kein Frame-Kopf/Slot/Rule (das ist nicht messbar), sondern
        // ein selbsterklärender Kontext-Block, den die KI-Gerüst-Erzeugung liest. Fehlt/leer die Achse
        // (Enum-fremd) → kein Block → Prompt byte-identisch.
        $balance = $this->menueBalanceDirektive($menueAchsen);
        if ($balance !== null) {
            $kontext['menue_zusammenstellung'] = $balance;
        }
        // Concept-Typ (#35): ein explizit als »Buffet« markiertes Concept baut STATIONEN (station-Slots,
        // parallel), kein Gänge-Menü. Der Prompt-Hint (»Buffet→station«) greift damit autoritativ statt
        // nur aus dem Freitext geraten zu werden. Menü/leer → kein Key → Kontext byte-identisch (gang).
        if (($menueAchsen['menue_typ'] ?? null) === 'buffet') {
            $kontext['struktur_typ'] = 'buffet';
        }

        // Trend-Wissen (Trendradar) additiv einspeisen — der Prompt läuft NICHT durch
        // contextFor(), also hier holen und als options['knowledge'] durchreichen (Routing
        // concept.brief_geruest → trend:discovery). Ohne Trend-Bestand liefert er leer.
        $trendWissen = app(KnowledgeContextService::class)->contextFor('concept.brief_geruest', $brief);
        $wissenOpts = $trendWissen['block'] !== ''
            ? ['knowledge' => $trendWissen['block'], 'knowledge_used' => $trendWissen['files_used']]
            : [];
        $proposal = app(AiGatewayService::class)->propose('concept.brief_geruest', $kontext, $wissenOpts);
        $werte = $proposal->werte ?? [];
        $slots = is_array($werte['slots'] ?? null) ? $werte['slots'] : [];
        if ($slots === []) {
            throw new RuntimeException('KI lieferte kein verwertbares Gerüst (keine Slots) — Brief präzisieren oder Gerüst manuell anlegen.');
        }

        // Konzept zuerst (als Gerüst-Owner), dann Struktur aus den KI-Werten — Draft + Lineage
        $concept = $this->concepts->create($team, [
            'name' => $name ?? (is_string($werte['name'] ?? null) && trim($werte['name']) !== '' ? trim($werte['name']) : 'Konzept-Entwurf aus Brief'),
            'status' => 'draft',
        ]);
        $concept->update([
            'created_via' => 'concept_generator_brief_' . $via,
            'description' => mb_substr($brief, 0, 2000),   // create() kennt description nicht — Brief als Kontext ans Konzept
        ]);

        $frame = $this->frames->frameFor($team, 'concept', $concept->id, 'ai_brief');
        $this->frames->setHead($team, $frame, [
            'target_price_pp' => is_numeric($werte['target_price_pp'] ?? null) ? (float) $werte['target_price_pp'] : null,
            'price_min_pp' => is_numeric($werte['price_min_pp'] ?? null) ? (float) $werte['price_min_pp'] : null,
            'price_max_pp' => is_numeric($werte['price_max_pp'] ?? null) ? (float) $werte['price_max_pp'] : null,
            'note' => 'Aus Brief generiert (KI-Vorschlag, Konfidenz ' . number_format((float) ($proposal->confidence ?? 0), 2) . ') — Rahmen prüfen.',
        ]);
        // Menü-Leitplanken (Concept-Tab): ein explizit gesetzter Zielpreis-Korridor je Person ist
        // autoritativ und überschreibt den KI-Vorschlag am Gerüst-Kopf (Nordstern: die Leitplanken
        // des Start-Tabs propagieren die Kaskade). Nur gesetzte Achsen greifen (leer = KI-Wert bleibt).
        $preisHead = $this->menuePreisHead($menueAchsen);
        if ($preisHead !== []) {
            $this->frames->setHead($team, $frame, $preisHead);
        }
        [$sichereSlots, $sichereRules] = $this->sanitizeGeruestWerte($slots, is_array($werte['rules'] ?? null) ? $werte['rules'] : []);
        if ($sichereSlots === []) {
            throw new RuntimeException('KI-Gerüst enthielt keine gültigen Slots — Brief präzisieren.');
        }
        // Spec 41 B3: Container-Struktur-Guard — ein Buffet/Menü darf nie auf 1 Position kollabieren
        // (RC-4/C1). Degeneriertes Gerüst → kanonisches Sektions-/Gänge-Gerüst; gute Gerüste unangetastet.
        $sichereSlots = $this->expandiereContainerGeruest($sichereSlots, $brief, $menueAchsen);
        // Menü-Leitplanke »Anzahl Gänge« (Concept-Tab) begrenzt das Gerüst autoritativ auf N gang-Slots
        // (Nordstern: die Leitplanken des Start-Tabs propagieren). Nur ein Deckel — überzählige Gänge
        // fallen weg, fehlt die Achse bleibt alles wie gehabt.
        $sichereSlots = $this->menueGaengeCap($sichereSlots, $menueAchsen);
        // Menü-Leitplanke »Diät-Quoten« (Concept-Tab): ein gesetzter Vegan-/Vegetarisch-Anteil je Person
        // wird als autoritative frame-Ebene-diet_quota-Rule (min %) ins Gerüst gelegt (schlägt eine
        // gleichnamige KI-Prozent-Quote). Weiche Zusammenstellungs-Vorgabe, kein Filter.
        $sichereRules = $this->menueDiaetQuotenMerge($sichereRules, $menueAchsen);
        $this->frames->replaceStructure($team, $frame, $sichereSlots, $sichereRules);

        // Assembler auf dem frischen Gerüst — Slots des leeren Konzepts füllen
        $ergebnis = $this->fuelleBestehendesKonzept($team, $concept, $frame->refresh());

        return $ergebnis + ['brief_confidence' => $proposal->confidence ?? null];
    }

    // ── Etappe 2b »Kreativ-Kopf«: Brief → Draft-Concept + Frame + Canvas + leere Fan-out-Slots ──

    /**
     * Kreativ-Kopf (der „alte Plan", vorab ausgearbeitet): Freitext-Brief → **Draft-Concept**
     * + **Planungs-Gerüst** (Frame, Reuse {@see geruestAusBriefFuerOwner}: Gänge/Zielpreis/Diät)
     * + **kreative Concept-Canvas** (`concept.plan`: name_claim/Leitidee/USP/Inszenierung/
     * Geschmackswelten) + **materialisierte LEERE Concept-Slots** als Fan-out-Ziele.
     *
     * Anders als {@see generiereAusBrief} (deterministischer Assembler FÜLLT die Slots aus dem
     * VK-Bestand) bleiben hier die Slots bewusst LEER — die spätere Kaskaden-Erfindung
     * ({@see PlanningCascadeService::fanoutConceptInvention}) erfindet je leerem Slot ein Gericht.
     * Dieser Service macht NUR die Vorbereitung; er startet KEINEN Fan-out (das ist der staged-Pfad
     * über den Concept-Step, nächster Roadmap-Chunk `existing_concept_id`).
     *
     * Fail-soft (Nordstern-Grundsatz): ein leerer/fehlgeschlagener `concept.plan` kippt weder
     * Concept noch Frame noch Slots — die kreative Canvas ist Kür, das Gerüst ist Pflicht. Das
     * Gerüst selbst (Reuse) wirft typisiert (KiNichtVerfuegbar/KiDeaktiviert), wenn die KI kein
     * verwertbares Gerüst liefert — dann steht kein sinnvoller Plan; den frisch angelegten Entwurf
     * räumen wir in diesem Fall wieder ab, damit kein leerer Draft-Rumpf zurückbleibt.
     *
     * @param  array<string,mixed>  $extra  Segment + Marken-Kontext (DNA-Kaskade) + Anlässe … →
     *   fließt an den Gerüst- UND den Plan-Prompt (damit Rahmen und Handschrift zur Marke passen).
     * @return array{concept: FoodAlchemistConcept, frame: FoodAlchemistPlanningFrame, slots: int, geruest_confidence: float|null, plan_confidence: float|null}
     */
    public function planAusBrief(Team $team, string $brief, array $extra = [], ?string $name = null, string $via = 'ui', array $menueAchsen = []): array
    {
        $brief = trim($brief);
        if ($brief === '') {
            throw new RuntimeException('Leerer Brief — Freitext nötig.');
        }

        // Draft-Concept zuerst (als Gerüst-/Canvas-Owner). Description = Brief als Kontext.
        $concept = $this->concepts->create($team, [
            'name' => $name !== null && trim($name) !== '' ? trim($name) : 'Konzept-Entwurf (KI-Plan)',
            'status' => 'draft',
        ]);
        $concept->update([
            'created_via' => 'concept_plan_' . $via,
            'description' => mb_substr($brief, 0, 2000),
        ]);

        // Frame — Reuse: KI baut Slots/Preise/Diät-Regeln aus dem Brief (Pflicht). Wirft die KI,
        // ist kein sinnvoller Plan möglich → frisch angelegten Draft (+ evtl. Rumpf-Frame) abräumen.
        try {
            $geruest = $this->geruestAusBriefFuerOwner($team, 'concept', (int) $concept->id, $brief, $extra, $via, $menueAchsen);
        } catch (\Throwable $e) {
            $this->frames->find('concept', (int) $concept->id)?->delete();
            $concept->delete();
            throw $e;
        }
        $frame = $geruest['frame'];
        // KI-Namensvorschlag übernehmen, wenn der Nutzer keinen gesetzt hat.
        if (($name === null || trim($name) === '') && is_string($geruest['name'] ?? null) && trim($geruest['name']) !== '') {
            $concept->update(['name' => trim($geruest['name'])]);
        }

        // Kreative Canvas (concept.plan) — fail-soft: leer/KI-aus kippt den Draft nicht.
        $planConfidence = $this->fuelleCanvasAusPlan($team, $concept, $brief, $extra);

        // Fan-out-Ziele: je Frame-Slot N LEERE Concept-Slots (NICHT befüllen).
        $slotZahl = $this->materialisiereLeereSlots($team, $concept, $frame);

        return [
            'concept' => $concept->refresh(),
            'frame' => $frame->refresh(),
            'slots' => $slotZahl,
            'geruest_confidence' => $geruest['confidence'] ?? null,
            'plan_confidence' => $planConfidence,
        ];
    }

    /**
     * `concept.plan` → kreative Concept-Canvas füllen (fail-soft). Liefert die Plan-Konfidenz
     * oder null, wenn der Call nichts Verwertbares brachte (KI aus/leer) — der Draft steht dann
     * trotzdem (Canvas ist Kür). Mapping der Prompt-`werte` auf die concept-Canvas
     * ({@see CanvasService::TEMPLATES}['concept']): die vier Langtext-/Text-Felder als Skalare,
     * `geschmackswelten` als repeatable — je Welt `value` = Überschrift (`claim`), die
     * ausführliche `description` ins Entry-`meta` (so rendert {@see CanvasService::promptKontext}
     * „Überschrift – Beschreibung" ohne Dublette).
     *
     * @param  array<string,mixed>  $extra
     */
    private function fuelleCanvasAusPlan(Team $team, FoodAlchemistConcept $concept, string $brief, array $extra): ?float
    {
        $kontext = array_merge(
            ['brief' => $brief],
            array_filter($extra, fn ($v) => $v !== null && $v !== '' && $v !== []),
        );

        // Gerouteten Wissens-Block aktiv bauen: propose() lädt nur GEBUNDENES Wissen automatisch,
        // die concept.plan-Routings (cross_cutting/domain/concept) fließen NUR, wenn der Aufrufer
        // sie über KnowledgeContextService auflöst und als options['knowledge'] mitgibt (wie
        // generiereAusBrief für concept.brief_geruest). Sonst liefe der Kreativ-Kopf wissens-blind.
        $wissen = app(KnowledgeContextService::class)->contextFor('concept.plan', $brief);
        $opts = $wissen['block'] !== ''
            ? ['knowledge' => $wissen['block'], 'knowledge_used' => $wissen['files_used']]
            : [];
        // concept.plan ist ein FOOD_DNA_KEY: die Marken-DNA-Kaskade (Team-DNA → … → Concept) erdet
        // die kreative Handschrift — propose() merged sie, wenn die Concept-Id mitkommt.
        $opts['food_dna_concept_id'] = (int) $concept->id;

        try {
            $proposal = app(AiGatewayService::class)->propose('concept.plan', $kontext, $opts);
        } catch (\Throwable) {
            return null;   // KI nicht verfügbar → Canvas bleibt leer, Draft bleibt stehen
        }
        $werte = is_array($proposal->werte ?? null) ? $proposal->werte : [];
        if ($werte === []) {
            return $proposal->confidence ?? null;
        }

        $canvas = app(CanvasService::class)->canvasFor($team, 'concept', 'concept', (int) $concept->id);

        // Skalare kreative Felder (nur nicht-leere setzen — fehlende Angaben nicht überschreiben).
        $skalar = [];
        foreach (['name_claim', 'leitidee', 'usp_eignung', 'inszenierung'] as $key) {
            if (isset($werte[$key]) && is_string($werte[$key]) && trim($werte[$key]) !== '') {
                $skalar[$key] = trim($werte[$key]);
            }
        }
        if ($skalar !== []) {
            app(CanvasService::class)->saveSkalare($canvas, $skalar);
        }

        // Geschmackswelten (repeatable): value = Überschrift (claim), description ins meta.
        $welten = is_array($werte['geschmackswelten'] ?? null) ? $werte['geschmackswelten'] : [];
        foreach ($welten as $welt) {
            if (! is_array($welt)) {
                continue;
            }
            $claim = is_string($welt['claim'] ?? null) ? trim($welt['claim']) : '';
            $beschr = is_string($welt['description'] ?? null) ? trim($welt['description']) : '';
            if ($claim === '' && $beschr === '') {
                continue;   // leere Welt überspringen
            }
            app(CanvasService::class)->addEntry(
                $canvas,
                'geschmackswelten',
                $claim !== '' ? $claim : $beschr,   // Überschrift; fehlt sie, trägt die Beschreibung
                ['claim' => null, 'description' => $beschr !== '' ? $beschr : null],
            );
        }

        return $proposal->confidence ?? null;
    }

    /**
     * Fan-out-Vorbereitung: je Frame-Slot N LEERE Concept-Slots anlegen (role = Slot-Label,
     * is_pflicht vom Frame-Slot geerbt), NICHT befüllen. Die Slots tragen den DB-Default-Typ
     * `gericht` (kein sales_recipe_id/package_id) → {@see PlanningCascadeService::fanoutConceptInvention}
     * erkennt sie als leere, erfindbare Positionen. N = target_count (mind. 1). Ein 2er-Slot wird
     * also zu zwei erfindbaren Positionen, spiegelbildlich zur Assembler-Auffüllung.
     *
     * @return int  Zahl der angelegten leeren Slots
     */
    private function materialisiereLeereSlots(Team $team, FoodAlchemistConcept $concept, FoodAlchemistPlanningFrame $frame): int
    {
        $frame->loadMissing('slots');
        $zahl = 0;
        foreach ($frame->slots as $frameSlot) {
            $n = max(1, (int) ($frameSlot->target_count ?? 1));
            for ($i = 0; $i < $n; $i++) {
                $this->concepts->addSlot($team, (int) $concept->id, [
                    'role' => $frameSlot->label,
                    'is_pflicht' => (bool) $frameSlot->is_pflicht,
                ]);
                $zahl++;
            }
        }

        return $zahl;
    }

    /**
     * Kickoff-Wizard: Freitext-Brief → KI baut NUR das Planungs-Gerüst (Slots+Rules)
     * für einen beliebigen Owner (foodbook|concept) — KEINE Konzept-Anlage, KEIN
     * Assembler. Der Foodbook-Pfad stoppt hier bewusst: der User prüft das Gerüst,
     * ruft dann „Struktur anwenden" (Slots→Kapitel) und lässt je Slot Vorschläge
     * generieren. Owner-agnostisch — der Frame ist owner-neutral (owner_type-Tupel).
     *
     * $extraKontext reicht Segment + Marken-Kontext (DNA-Kaskade) an den Prompt durch,
     * damit das Gerüst zur Bespielung passt (Fine Dining vs. Volumen).
     *
     * Reine KI-Frame-Erzeugung — wirft KiNichtVerfuegbar/KiDeaktiviert (typisiert),
     * die Aufrufer (Livewire/Tool) fangen als UI-Fehler ab (kein 500).
     *
     * $menueAchsen (Concept-Menü-Leitplanken, kanonische menue_*-Keys) werden — falls gesetzt —
     * autoritativ auf das Gerüst angewandt (Preis-Korridor-Kopf, Gänge/Stationen-Cap, Diät-Quoten,
     * Portfolio-Balance + Concept-Typ als Kontext). Leer/fehlend = byte-identisch (No-op-Helfer) →
     * der Foodbook-Kickoff-Aufrufer bleibt unberührt. Damit erbt auch der plan-first-Pfad
     * ({@see planAusBrief}) die Concept-Tab-Leitplanken (#45/#53).
     *
     * @param array<string,mixed> $extraKontext segment · marken_kontext · anlaesse …
     * @param array<string,mixed> $menueAchsen  Concept-Menü-Leitplanken (kanonische menue_*-Keys)
     * @return array{frame: FoodAlchemistPlanningFrame, confidence: float|null, slots: int, name: ?string}
     */
    public function geruestAusBriefFuerOwner(Team $team, string $ownerType, int $ownerId, string $brief, array $extraKontext = [], string $via = 'ui', array $menueAchsen = []): array
    {
        $brief = trim($brief);
        if ($brief === '') {
            throw new RuntimeException('Leerer Brief — mindestens Anlass/Gäste nötig.');
        }

        $kontext = array_merge([
            'brief' => $brief,
            'diaet_vokabular' => \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule::DIET_FORMS,
            'allergen_keys' => FoodAlchemistGp::ALLERGEN_FIELDS,
        ], array_filter($extraKontext, fn ($v) => $v !== null && $v !== '' && $v !== []));
        // Menü-Leitplanken in den Prompt-Kontext (byte-identisch bei leer): Portfolio-Balance-Direktive
        // + Concept-Typ (Buffet baut station-Slots). Spiegelt {@see generiereAusBrief}.
        $balance = $this->menueBalanceDirektive($menueAchsen);
        if ($balance !== null) {
            $kontext['menue_zusammenstellung'] = $balance;
        }
        if (($menueAchsen['menue_typ'] ?? null) === 'buffet') {
            $kontext['struktur_typ'] = 'buffet';
        }

        // Trend-Wissen (Trendradar) additiv einspeisen — der Prompt läuft NICHT durch
        // contextFor(), also hier holen und als options['knowledge'] durchreichen (Routing
        // concept.brief_geruest → trend:discovery). Ohne Trend-Bestand liefert er leer.
        $trendWissen = app(KnowledgeContextService::class)->contextFor('concept.brief_geruest', $brief);
        $wissenOpts = $trendWissen['block'] !== ''
            ? ['knowledge' => $trendWissen['block'], 'knowledge_used' => $trendWissen['files_used']]
            : [];
        $proposal = app(AiGatewayService::class)->propose('concept.brief_geruest', $kontext, $wissenOpts);
        $werte = $proposal->werte ?? [];
        $slots = is_array($werte['slots'] ?? null) ? $werte['slots'] : [];
        if ($slots === []) {
            throw new RuntimeException('KI lieferte kein verwertbares Gerüst (keine Slots) — Brief präzisieren oder Gerüst manuell anlegen.');
        }

        $frame = $this->frames->frameFor($team, $ownerType, $ownerId, 'ai_brief_' . $via);
        $this->frames->setHead($team, $frame, [
            'target_price_pp' => is_numeric($werte['target_price_pp'] ?? null) ? (float) $werte['target_price_pp'] : null,
            'price_min_pp' => is_numeric($werte['price_min_pp'] ?? null) ? (float) $werte['price_min_pp'] : null,
            'price_max_pp' => is_numeric($werte['price_max_pp'] ?? null) ? (float) $werte['price_max_pp'] : null,
            'note' => 'Aus Brief generiert (KI-Vorschlag, Konfidenz ' . number_format((float) ($proposal->confidence ?? 0), 2) . ') — Rahmen prüfen, dann „Struktur anwenden".',
        ]);
        // Preis-Korridor-Kopf autoritativ aus den Menü-Achsen überschreiben (nur gesetzte Achsen).
        $preisHead = $this->menuePreisHead($menueAchsen);
        if ($preisHead !== []) {
            $this->frames->setHead($team, $frame, $preisHead);
        }
        [$sichereSlots, $sichereRules] = $this->sanitizeGeruestWerte($slots, is_array($werte['rules'] ?? null) ? $werte['rules'] : []);
        if ($sichereSlots === []) {
            throw new RuntimeException('KI-Gerüst enthielt keine gültigen Slots — Brief präzisieren.');
        }
        // Spec 41 B3: Container-Struktur-Guard (s. generiereAusBrief) — Buffet/Menü nie auf 1 Position kollabieren.
        $sichereSlots = $this->expandiereContainerGeruest($sichereSlots, $brief, $menueAchsen);
        // Gänge/Stationen-Cap (typ-abhängig) + Diät-Quoten autoritativ ins Gerüst (No-op bei leeren Achsen).
        $sichereSlots = $this->menueGaengeCap($sichereSlots, $menueAchsen);
        $sichereRules = $this->menueDiaetQuotenMerge($sichereRules, $menueAchsen);
        $this->frames->replaceStructure($team, $frame, $sichereSlots, $sichereRules);

        return [
            'frame' => $frame->refresh(),
            'confidence' => $proposal->confidence ?? null,
            'slots' => count($sichereSlots),
            'name' => is_string($werte['name'] ?? null) && trim($werte['name']) !== '' ? trim($werte['name']) : null,
        ];
    }

    /**
     * Gerüst-Kopf-Überschreibungen aus den Concept-Menü-Achsen (nur Preis-Korridor je Person).
     * `reglerParams` hat die Roh-Eingaben als kanonische `_pp`-Keys geparst; hier werden sie auf
     * die Frame-Kopf-Felder gemappt. Nur vorhandene, numerische Achsen erzeugen einen Key
     * (fehlend = kein Key → {@see PlanningFrameService::setHead} lässt den KI-Wert stehen).
     *
     * @param  array<string,mixed>  $achsen
     * @return array<string,float>
     */
    private function menuePreisHead(array $achsen): array
    {
        $map = [
            'menue_preis_ziel_pp' => 'target_price_pp',
            'menue_preis_min_pp' => 'price_min_pp',
            'menue_preis_max_pp' => 'price_max_pp',
        ];
        $out = [];
        foreach ($map as $quelle => $ziel) {
            if (isset($achsen[$quelle]) && is_numeric($achsen[$quelle])) {
                $out[$ziel] = (float) $achsen[$quelle];
            }
        }

        return $out;
    }

    /**
     * Menü-Leitplanke »Anzahl Gänge« (menue_gaenge, Concept-Tab) deckelt das Gerüst autoritativ auf
     * N gang-Slots — ein 4-Gänge-Menü hat vier Gang-Slots (Nordstern: die Leitplanken des Start-Tabs
     * propagieren die Kaskade). Bewusst NUR ein Deckel: überzählige gang-Slots werden in Dramaturgie-
     * Reihenfolge abgeschnitten (die ersten N bleiben); produzierte die KI weniger Gänge als N, bleibt
     * das Gerüst unangetastet — es werden keine Gänge erfunden (das Gerüst-System erfindet nichts).
     * Der gedeckelte Slot-Typ folgt dem Concept-Typ (#35): »Menü« (Default) deckelt gang-Slots (ein
     * 4-Gänge-Menü = vier Gang-Slots), »Buffet« (menue_typ='buffet') deckelt station-Slots (»Anzahl
     * Stationen«). Der jeweils andere Typ + kapitel-Slots bleiben unberührt. Fehlt die Achse
     * (kein/leeres menue_gaenge), bleibt das Gerüst byte-identisch (leer = keine Vorgabe).
     *
     * @param  list<array<string,mixed>>  $slots  bereits sanitisierte Slots (Reihenfolge = Dramaturgie)
     * @param  array<string,mixed>  $achsen
     * @return list<array<string,mixed>>
     */
    private function menueGaengeCap(array $slots, array $achsen): array
    {
        $n = $achsen['menue_gaenge'] ?? null;
        if (! is_numeric($n) || (int) $n < 1) {
            return $slots;
        }
        $n = (int) $n;
        // Buffet deckelt Stationen, Menü (Default/leer) deckelt Gänge.
        $capType = ($achsen['menue_typ'] ?? null) === 'buffet' ? 'station' : 'gang';

        $zaehler = 0;
        $out = [];
        foreach ($slots as $slot) {
            if (($slot['slot_type'] ?? null) === $capType) {
                if (++$zaehler > $n) {
                    continue;   // überzählige Position abschneiden — Dramaturgie-Reihenfolge bleibt erhalten
                }
            }
            $out[] = $slot;
        }

        return array_values($out);
    }

    /**
     * Spec 41 B3 (§3 Regelwerk Concept, gegen RC-4 / Fall 003 »Lunchbuffet«): deterministischer
     * Struktur-Guard. Ein Container-Brief (Buffet/Menü) MUSS ein Mehr-Sektions-Gerüst ergeben, NIE
     * eine atomare Position (»1. Lunchbuffet«). Kollabiert das KI-Gerüst für den erkannten Archetyp
     * (< 2 archetyp-eigene Slots), wird es durch das kanonische Sektions-/Gänge-Gerüst ERSETZT
     * (Regelwerk_Concept §4). Ist kein Container-Archetyp erkennbar ODER ist das Gerüst bereits
     * mehrgliedrig, bleibt es UNANGETASTET (golden-safe — gute KI-Gerüste werden nicht gestört).
     * Läuft VOR {@see menueGaengeCap} (dessen Deckel trimmt das erzwungene Gerüst danach ggf. wieder
     * auf die »Anzahl Gänge«-Leitplanke).
     *
     * @param  list<array<string,mixed>>  $slots  sanitisierte Slots
     * @param  array<string,mixed>  $achsen
     * @return list<array<string,mixed>>
     */
    private function expandiereContainerGeruest(array $slots, string $brief, array $achsen): array
    {
        $archetyp = $this->erkenneContainerArchetyp($brief, $achsen);
        if ($archetyp === null) {
            return $slots;   // kein Container-Archetyp → nichts erzwingen
        }

        $eigenTyp = $archetyp === 'buffet' ? 'station' : 'gang';
        $vorhanden = 0;
        foreach ($slots as $s) {
            if (($s['slot_type'] ?? null) === $eigenTyp) {
                $vorhanden++;
            }
        }
        if ($vorhanden >= 2) {
            return $slots;   // bereits echtes Mehr-Sektions-/Gänge-Gerüst → unangetastet
        }

        return $archetyp === 'buffet'
            ? $this->buffetSektionsGeruest()
            : $this->menueGangGeruest($achsen);
    }

    /**
     * Container-Archetyp aus Concept-Achsen + Brief: `buffet` (Sektionen) | `menue` (Gänge) | null
     * (kein Container erkennbar → kein Eingriff). `menue_typ='buffet'` ist autoritativ; sonst
     * Brief-Schlagworte. Rein lesend, nie Fehler.
     *
     * @param  array<string,mixed>  $achsen
     */
    private function erkenneContainerArchetyp(string $brief, array $achsen): ?string
    {
        $typ = $achsen['menue_typ'] ?? null;
        if ($typ === 'buffet') {
            return 'buffet';
        }
        if (in_array($typ, ['menu', 'menue', 'menü'], true)) {
            return 'menue';
        }
        $b = ' ' . mb_strtolower($brief) . ' ';
        // Buffet: »buffet« ist distinktiv genug als Teilstring (deckt lunchbuffet/flying buffet).
        if (preg_match('/(buffet|brunch)/u', $b)) {
            return 'buffet';
        }
        // Menü: whitespace-verankert (NICHT \b — unter /u ist \b bei Umlauten ASCII-tückisch, sonst
        // triggert »menüteller« fälschlich). Nur eigenständige Menü-/Gänge-Wörter zählen.
        if (preg_match('/(^|\s)(men[üu]e?s?|mehrg[äa]nge?|\d+[- ]?g[äa]nge|g[äa]nge[- ]?men[üu]e?)(\s|$)/u', $b)) {
            return 'menue';
        }

        return null;
    }

    /**
     * Kanonisches Buffet-Sektions-Gerüst (Regelwerk_Concept §4.2 / [[Menue_Architektur]] + [[Anlass_Serviceformen]]).
     * Carving-Pflicht > 50 Pax + Breite 8–15 Positionen trägt das Regelwerk im Prompt; hier steht das
     * belastbare Sektions-Skelett mit Platzhalter-`target_count`.
     *
     * @return list<array<string,mixed>>
     */
    private function buffetSektionsGeruest(): array
    {
        $mk = fn (string $label, int $count, bool $pflicht): array => [
            'label' => $label, 'slot_type' => 'station', 'target_count' => $count,
            'price_anchor' => null, 'price_min' => null, 'price_max' => null,
            'is_pflicht' => $pflicht, 'rules' => [],
        ];

        return [
            $mk('Kalte Vorspeisen / Salate', 3, true),
            $mk('Suppe', 1, false),
            $mk('Warme Hauptkomponente', 2, true),
            $mk('Sättigungsbeilagen (Stärke + Gemüse)', 2, true),
            $mk('Dessert / Sweet-Table', 2, true),
            $mk('Getränke', 1, false),
        ];
    }

    /**
     * Kanonisches Menü-Gänge-Gerüst ([[Menue_Architektur]]). Gang-Zahl = »Anzahl Gänge«-Leitplanke
     * (menue_gaenge), sonst 3; auf 3–9 geklemmt. Dramaturgie endet immer mit dem Dessert.
     *
     * @param  array<string,mixed>  $achsen
     * @return list<array<string,mixed>>
     */
    private function menueGangGeruest(array $achsen): array
    {
        $n = $achsen['menue_gaenge'] ?? null;
        $n = (is_numeric($n) && (int) $n >= 1) ? (int) $n : 3;
        $n = max(3, min(9, $n));

        $out = [];
        foreach ($this->menueGangLeiter($n) as $label) {
            $out[] = [
                'label' => $label, 'slot_type' => 'gang', 'target_count' => 1,
                'price_anchor' => null, 'price_min' => null, 'price_max' => null,
                'is_pflicht' => true, 'rules' => [],
            ];
        }

        return $out;
    }

    /**
     * Dramaturgische Gang-Leiter (3–9) nach [[Menue_Architektur]] — Spannungsbogen aufsteigend,
     * Hauptgang als Höhepunkt, Dessert zuletzt.
     *
     * @return list<string>
     */
    private function menueGangLeiter(int $n): array
    {
        $leiter = [
            3 => ['Vorspeise', 'Hauptgang', 'Dessert'],
            4 => ['Vorspeise', 'Zwischengang', 'Hauptgang', 'Dessert'],
            5 => ['Gruß aus der Küche', 'Vorspeise', 'Zwischengang', 'Hauptgang', 'Dessert'],
            6 => ['Gruß aus der Küche', 'Vorspeise', 'Suppe', 'Zwischengang', 'Hauptgang', 'Dessert'],
            7 => ['Gruß aus der Küche', 'Kalte Vorspeise', 'Suppe', 'Zwischengang (Fisch)', 'Hauptgang', 'Käsegang', 'Dessert'],
            8 => ['Gruß aus der Küche', 'Kalte Vorspeise', 'Suppe', 'Warme Vorspeise', 'Zwischengang (Fisch)', 'Hauptgang', 'Käsegang', 'Dessert'],
            9 => ['Gruß aus der Küche', 'Amuse-Bouche', 'Kalte Vorspeise', 'Suppe', 'Zwischengang (Fisch)', 'Warme Vorspeise', 'Hauptgang', 'Käsegang', 'Dessert'],
        ];

        return $leiter[$n] ?? $leiter[3];
    }

    /**
     * Menü-Leitplanke »Diät-Quoten« (menue_quote_{vegan,vegetarisch}_pct, Concept-Tab) → autoritative
     * frame-Ebene-diet_quota-Regeln (operator min, unit percent) im Gerüst. Der Concept-Tab-Anteil ist
     * eine WEICHE Zusammenstellungs-Vorgabe (»mind. X % vegan/vegetarisch«) — kein Menü-Filter (der harte
     * Ausschluss läuft über `diaet_hart`) — und landet als Soll-Quote am Rahmen, die der deterministische
     * Assembler/das UI liest. Nordstern: die Leitplanken des Start-Tabs propagieren die Kaskade und
     * schlagen den KI-Vorschlag.
     *
     * »vegetarisch« mappt auf das kanonische diet_form `vegi` ({@see FoodAlchemistPlanningFrameRule::DIET_FORMS}).
     * »Autoritativ« = eine für dieselbe Diät-Form gesetzte KI-Prozent-Quote wird ERSETZT (nicht dupliziert);
     * count-basierte Quoten und andere Diät-Formen bleiben unangetastet. Ein Anteil < 1 % ist keine echte
     * Leitplanke (0 = keine Vorgabe) → wird übersprungen; fehlt/leer/ungültig die Achse, bleiben die
     * KI-Regeln byte-identisch.
     *
     * @param  list<array<string,mixed>>  $rules   bereits sanitisierte Frame-Regeln (KI-Gerüst)
     * @param  array<string,mixed>  $achsen
     * @return list<array<string,mixed>>
     */
    private function menueDiaetQuotenMerge(array $rules, array $achsen): array
    {
        $map = [
            'menue_quote_vegan_pct' => 'vegan',
            'menue_quote_vegetarisch_pct' => 'vegi',
        ];
        $neu = [];
        $ersetzt = [];   // diet_form → true (welche KI-Prozent-Quoten die Achse verdrängt)
        foreach ($map as $quelle => $dietForm) {
            $wert = $achsen[$quelle] ?? null;
            if (! is_numeric($wert)) {
                continue;
            }
            $pct = (int) $wert;
            if ($pct < 1 || $pct > 100) {
                continue;   // 0 = keine Vorgabe; >100 defensiv (reglerParams filtert schon 0–100)
            }
            $ersetzt[$dietForm] = true;
            $neu[] = [
                'rule_type' => 'diet_quota',
                'ref_key' => $dietForm,
                'ref_id' => null,
                'operator' => 'min',
                'value_num' => (float) $pct,
                'unit' => 'percent',
                'value_text' => null,
                'severity' => null,
            ];
        }
        if ($neu === []) {
            return $rules;   // keine gesetzte Achse → KI-Regeln unangetastet
        }
        // autoritativ: eine KI-Prozent-Quote derselben Diät-Form weicht der Achse (kein Doppel-Eintrag)
        $behalten = array_filter($rules, fn ($r) => ! (
            ($r['rule_type'] ?? null) === 'diet_quota'
            && ($r['unit'] ?? null) === 'percent'
            && isset($ersetzt[$r['ref_key'] ?? null])
        ));

        return array_values(array_merge($behalten, $neu));
    }

    /**
     * Menü-Leitplanke »Portfolio-Balance« (menue_balance, Concept-Tab) → selbsterklärende
     * Zusammenstellungs-Direktive für den KI-Gerüst-Prompt. Anders als Preis/Gänge/Diät-Quote ist
     * »Balance« nichts Messbares am Frame (Kopf/Slot/Rule), sondern eine WEICHE Vorgabe, wie breit das
     * Menü über Proteine/Warengruppen/Garmethoden streut — sie steuert die Zusammenstellung, nicht die
     * Struktur. Deshalb als Kontext-Block (der Prompt serialisiert den ganzen Kontext als JSON), nicht
     * als Regel. Die zwei Stile spiegeln 1:1 das UI-Enum {@see \Platform\FoodAlchemist\Livewire\Planung\Index::MENUE_BALANCE}
     * — kein erfundener Schwellwert. Nur ein bekannter Enum-Wert erzeugt einen Block; fehlt/leer/fremd
     * die Achse → null → Prompt byte-identisch (leer = keine Vorgabe).
     *
     * @param  array<string,mixed>  $achsen
     * @return array{stil: string, hinweis: string}|null
     */
    private function menueBalanceDirektive(array $achsen): ?array
    {
        $stil = is_string($achsen['menue_balance'] ?? null) ? trim($achsen['menue_balance']) : '';
        $hinweise = [
            'ausgewogen' => 'Stelle das Menü AUSGEWOGEN zusammen: streue breit über Proteine, Warengruppen '
                . 'und Garmethoden; vermeide es, dieselbe Hauptzutat oder Garart über die Gänge zu wiederholen.',
            'fokussiert' => 'Stelle das Menü FOKUSSIERT zusammen: ein klarer roter Faden (Leitzutat, Region '
                . 'oder Technik) über die Gänge statt maximaler Vielfalt.',
        ];
        if (! isset($hinweise[$stil])) {
            return null;   // unbekannt/leer = keine Vorgabe (reglerParams lässt nur die Enum-Werte durch)
        }

        return ['stil' => $stil, 'hinweis' => $hinweise[$stil]];
    }

    /**
     * 06·H3: opt-in Favoriten-Block für den Brief→Gerüst-KI-Schritt.
     * $convenienceOnly (H4b): nur Convenience-getaggte Favoriten.
     * null, wenn nichts (Passendes) gepinnt ist. Der Gerüst-Assembler selbst ist
     * deterministisch (wählt aus Bestand, erfindet nicht) — dort braucht es keinen Block.
     */
    private function favoritesHint(Team $team, bool $convenienceOnly = false): ?array
    {
        $treffer = FoodAlchemistGp::query()
            ->visibleToTeam($team)
            ->favorites()
            ->when($convenienceOnly, fn ($q) => $q->where('tag_is_convenience', true))
            ->limit(80)
            ->pluck('name')
            ->all();

        if ($treffer === []) {
            return null;
        }

        $was = $convenienceOnly ? 'BEVORZUGTE CONVENIENCE-BAUSTEINE (Haus-Standard)' : 'BEVORZUGTE HAUS-FAVORITEN (Grundprodukte)';

        return [
            'hinweis' => $was . ': berücksichtige diese Produkte '
                . 'bei der Konzept-Dramaturgie bevorzugt; ergänze frei, wo die Liste nichts hergibt.',
            'produkte' => $treffer,
        ];
    }

    /** Assembler-Kern auf ein EXISTIERENDES Konzept anwenden (Brief-Pfad: Gerüst hängt schon dran). */
    private function fuelleBestehendesKonzept(Team $team, FoodAlchemistConcept $concept, FoodAlchemistPlanningFrame $frame): array
    {
        // Wiederverwendung: generiereAusGeruest legt normalerweise ein NEUES Konzept an.
        // Hier existiert es schon (als Gerüst-Owner) — gleicher Ablauf, ohne Neu-Anlage.
        $frame->loadMissing(['slots.rules', 'rules']);
        if ($frame->slots->isEmpty()) {
            throw new RuntimeException('Gerüst hat keine Slots.');
        }
        $pool = $this->pool->fuerFrame($team, $frame);

        $protokoll = [];
        $gewaehlt = collect();
        $gewaehlteAnker = [];
        foreach ($frame->slots as $frameSlot) {
            $n = max(1, (int) ($frameSlot->target_count ?? 1));
            $kandidaten = $this->pool->filterFuerSlot($pool, $frame, $frameSlot)->reject(fn ($k) => $gewaehlt->has($k['id']));
            $quoten = $frameSlot->rules->where('rule_type', 'diet_quota')->where('operator', '!=', 'max')->where('unit', 'count');

            $slotWahl = collect();
            foreach ($quoten as $q) {
                $bedarf = (int) ceil((float) $q->value_num);
                while ($bedarf > 0 && $slotWahl->count() < $n) {
                    $treffer = $this->besterKandidat($kandidaten->filter(fn ($k) => $k['diet_form'] === $q->ref_key && ! $slotWahl->has($k['id'])), $gewaehlteAnker, $frameSlot);
                    if ($treffer === null) {
                        break;
                    }
                    $slotWahl->put($treffer['id'], $treffer);
                    $gewaehlteAnker = array_unique(array_merge($gewaehlteAnker, $treffer['anker']));
                    $bedarf--;
                }
            }
            while ($slotWahl->count() < $n) {
                $treffer = $this->besterKandidat($kandidaten->reject(fn ($k) => $slotWahl->has($k['id'])), $gewaehlteAnker, $frameSlot);
                if ($treffer === null) {
                    break;
                }
                $slotWahl->put($treffer['id'], $treffer);
                $gewaehlteAnker = array_unique(array_merge($gewaehlteAnker, $treffer['anker']));
            }

            if ($slotWahl->isEmpty()) {
                $begruendung = 'Kein VK-Gericht erfüllt die Vorgaben (' . $this->pool->filterBeschreibung($frame, $frameSlot) . ') — Slot bewusst leer gelassen.';
                $leer = $this->concepts->addSlot($team, $concept->id, ['role' => $frameSlot->label]);
                $this->concepts->updateSlot($team, $leer->id, ['note' => $begruendung]);
                $protokoll[] = ['slot' => $frameSlot->label, 'status' => 'leer', 'begruendung' => $begruendung, 'gerichte' => []];

                continue;
            }
            foreach ($slotWahl as $wahl) {
                $slot = $this->concepts->addSlot($team, $concept->id, ['role' => $frameSlot->label]);
                $this->concepts->fillSlot($team, $slot->id, ['sales_recipe_id' => $wahl['id'], 'type' => 'gericht']);
            }
            // put() statt merge(): merge renummeriert Integer-Keys — die Gericht-IDs sind die Keys!
            foreach ($slotWahl as $id => $wahl) {
                $gewaehlt->put($id, $wahl);
            }
            $fehlend = $n - $slotWahl->count();
            $protokoll[] = [
                'slot' => $frameSlot->label,
                'status' => $fehlend > 0 ? 'teilbefuellt' : 'befuellt',
                'begruendung' => $fehlend > 0 ? "{$fehlend} von {$n} Plätzen unbefüllbar (" . $this->pool->filterBeschreibung($frame, $frameSlot) . ')' : null,
                'gerichte' => $slotWahl->map(fn ($k) => ['id' => $k['id'], 'name' => $k['name'], 'diet_form' => $k['diet_form'], 'sales_net' => $k['sales_net']])->values()->all(),
            ];
        }

        $dishes = FoodAlchemistRecipe::whereIn('id', $gewaehlt->keys())->get()->all();

        return [
            'concept' => $concept->refresh(),
            'protokoll' => $protokoll,
            'kohaesion' => $this->pairing->menuCohesion($dishes),
            'coverage' => $this->coverage->coverage($team, 'concept', $concept->id),
        ];
    }

    /**
     * Phase 3 (Weg B): gerankte Vorschläge für EINEN Slot — read-only, legt KEIN Konzept an.
     * Wiederverwendung derselben Assembler-Logik wie generiereAusGeruest (harte Filter aus den
     * Gerüst-Regeln, kohäsives Ranking über den Pairing-Graphen), nur ohne Persistenz: liefert
     * die Top-N Gerichte, aus denen der Mensch abstimmt → übernehmen ist FoodbookService-Sache.
     *
     * @return list<array{id:int, name:string, diet_form:?string, sales_net:?float}>
     */
    public function slotVorschlaege(Team $team, FoodAlchemistPlanningFrame $frame, FoodAlchemistPlanningFrameSlot $slot, int $limit = 6, ?string $zielNiveau = null, ?string $zielConvenience = null): array
    {
        // Eine Ranking-Wahrheit: der schlanke Weg-B-Aufruf ist die Projektion des begründeten.
        $res = $this->slotKandidaten($team, $frame, $slot, [], $limit, $zielNiveau, $zielConvenience);

        return array_map(fn ($k) => [
            'id' => $k['id'], 'name' => $k['name'], 'diet_form' => $k['diet_form'], 'sales_net' => $k['sales_net'],
        ], $res['kandidaten']);
    }

    /**
     * L4 (Spec 03): dieselbe Slot-Rangliste, aber MIT sichtbaren Ranking-Faktoren und
     * ehrlichem Hinweis, wenn nichts (mehr) zulässig ist — die Fläche dafür ist der
     * Concepter-Editor („schlag mir für diese Position was vor"). Read-only, kein LLM.
     *
     * `$belegteRecipeIds` = die bereits gesetzten Gerichte des Konzepts: ihre Anker gehen
     * als Kohäsions-Basis ins Ranking (der Vorschlag passt zur BESTEHENDEN Menüfolge, nicht
     * nur zu sich selbst) und sie werden nicht erneut vorgeschlagen. Leer = Verhalten wie
     * der Generator-Pfad, der sein Menü von Null aufbaut.
     *
     * @param  list<int>  $belegteRecipeIds
     * @return array{kandidaten: list<array{id:int, name:string, diet_form:?string, sales_net:?float, faktoren:array<string,int|float>, begruendung:string}>, hinweis:?string}
     */
    public function slotKandidaten(Team $team, FoodAlchemistPlanningFrame $frame, FoodAlchemistPlanningFrameSlot $slot, array $belegteRecipeIds = [], int $limit = 3, ?string $zielNiveau = null, ?string $zielConvenience = null): array
    {
        if ($frame->exists) {
            $frame->loadMissing(['slots.rules', 'rules']);
        }
        // Convenience-Daten (GP-Tags) nur laden, wenn die Leitplanke wirklich diskriminiert
        // (from_scratch/voll_convenience) — teil_convenience/null bleibt neutral + günstig.
        $mitConvenience = in_array($zielConvenience, ['from_scratch', 'voll_convenience'], true);
        $pool = $this->pool->fuerFrame($team, $frame, $mitConvenience);

        // Kohäsions-Basis aus dem Pool selbst (keine zweite Anker-Auflösung): Gerichte, die
        // nicht im Pool sind (draft/Slot-Variante), liefern eben keine Anker — ehrlich, nicht geraten.
        $belegteRecipeIds = array_values(array_unique(array_map('intval', $belegteRecipeIds)));
        $basisAnker = [];
        foreach ($belegteRecipeIds as $rid) {
            if ($pool->has($rid)) {
                $basisAnker = array_merge($basisAnker, $pool[$rid]['anker']);
            }
        }
        $basisAnker = array_values(array_unique($basisAnker));

        $kandidaten = $this->pool->filterFuerSlot($pool, $frame, $slot)
            ->reject(fn ($k) => in_array((int) $k['id'], $belegteRecipeIds, true));
        if ($kandidaten->isEmpty()) {
            return ['kandidaten' => [], 'hinweis' => 'Kein Gericht erfüllt die Vorgaben (' . $this->pool->filterBeschreibung($frame, $slot) . ').'];
        }

        $limit = max(1, $limit);
        $out = [];
        $gewaehlteAnker = $basisAnker;
        $gewaehltIds = [];
        while (count($out) < $limit) {
            $rest = $kandidaten->reject(fn ($k) => in_array($k['id'], $gewaehltIds, true));
            $treffer = $this->besterKandidat($rest, $gewaehlteAnker, $slot, $zielNiveau, $zielConvenience);
            if ($treffer === null) {
                break;
            }
            $faktoren = [
                'semantik' => (int) ($treffer['semantik'] ?? 0),
                'kohaesion' => round((float) ($treffer['score'] ?? 0.0), 3),
                'ankerdichte' => (int) ($treffer['ankerdichte'] ?? 0),
                'preisnaehe' => round((float) ($treffer['preisnaehe'] ?? 0.0), 2),
                'niveau_match' => (int) ($treffer['niveau_match'] ?? 0),
                'convenience_match' => round((float) ($treffer['convenience_match'] ?? 0.0), 3),
            ];
            $out[] = [
                'id' => (int) $treffer['id'], 'name' => (string) $treffer['name'],
                'diet_form' => $treffer['diet_form'], 'sales_net' => $treffer['sales_net'],
                'faktoren' => $faktoren,
                'begruendung' => $this->rankingBegruendung(
                    $faktoren, $slot, $basisAnker !== [], $zielNiveau, $zielConvenience,
                    $treffer['sales_net'] !== null ? (float) $treffer['sales_net'] : null,
                ),
            ];
            $gewaehltIds[] = $treffer['id'];
            $gewaehlteAnker = array_unique(array_merge($gewaehlteAnker, $treffer['anker']));
        }

        return [
            'kandidaten' => $out,
            'hinweis' => count($out) < $limit
                ? 'Nur ' . count($out) . ' zulässige Treffer (' . $this->pool->filterBeschreibung($frame, $slot) . ').'
                : null,
        ];
    }

    /**
     * Ranking-Faktoren als lesbare Kette — dieselbe Reihenfolge wie die Sortierung in
     * besterKandidat, damit die Begründung erklärt, warum DIESER Kandidat oben steht.
     * Faktoren, die im Kontext nichts entscheiden (kein Preis-Anker, kein Ziel-Niveau),
     * bleiben weg statt als Null-Wert Rauschen zu machen.
     *
     * @param  array<string,int|float>  $f
     */
    private function rankingBegruendung(array $f, FoodAlchemistPlanningFrameSlot $slot, bool $mitKohaesion, ?string $zielNiveau, ?string $zielConvenience, ?float $salesNet = null): string
    {
        $teile = [];
        if ($f['semantik'] === 1) {
            $teile[] = 'Hauptgruppe passt zur Rolle';
        }
        if ($zielNiveau !== null) {
            $teile[] = $f['niveau_match'] === 1 ? "Niveau {$zielNiveau} geeignet" : "Niveau {$zielNiveau} nicht gestempelt";
        }
        if (in_array($zielConvenience, ['from_scratch', 'voll_convenience'], true)) {
            $teile[] = ($zielConvenience === 'from_scratch' ? 'Scratch-Anteil ' : 'Convenience-Anteil ')
                . number_format($f['convenience_match'] * 100, 0, ',', '.') . '%';
        }
        if ($mitKohaesion) {
            $teile[] = $f['kohaesion'] > 0.0
                ? 'Aroma-Nähe zur gesetzten Folge ' . number_format($f['kohaesion'], 2, ',', '.')
                : 'keine Aroma-Kante zur gesetzten Folge';
        }
        $teile[] = $f['ankerdichte'] . ' Aroma-Anker';
        if ($slot->price_anchor !== null) {
            // preisnaehe ist 0.0 in ZWEI Fällen (Preis genau am Anker · gar kein VK-Preis) —
            // deshalb entscheidet sales_net, nicht das Vorzeichen.
            $teile[] = $salesNet !== null
                ? 'Preis-Abstand zum Anker ' . number_format(abs($f['preisnaehe']), 2, ',', '.') . ' €'
                : 'kein VK-Preis (Anker ' . number_format((float) $slot->price_anchor, 2, ',', '.') . ' €)';
        }

        return implode(' · ', $teile);
    }

    // ── Ranking ─────────────────────────────────────────────────────────
    // Der Kandidaten-Pool selbst (Aufbau · Slot-Filter · Filter-Beschreibung) liegt
    // seit 12·S2a-1 im geteilten MenuCandidatePoolService — der Marge-Solver (R2.4)
    // wählt aus DEMSELBEN Pool, statt eine zweite Auswahl-Wahrheit aufzumachen.

    /**
     * Slot-Semantik: passt die Speisen-Hauptgruppe des Gerichts zum Slot-Label?
     *
     * 12·S3a: die Logik liegt jetzt im geteilten {@see MenuCandidatePoolService::slotSemantik}
     * (dort entsteht `hg_label`), damit Generator, Weg-B-Vorschlag und Marge-Solver
     * nicht drei Auslegungen desselben Vergleichs pflegen. Diese Methode bleibt als
     * benannter Zugang stehen und delegiert — eine Wahrheit, zwei Türen.
     */
    public static function slotSemantik(string $slotLabel, string $hgLabel): int
    {
        return MenuCandidatePoolService::slotSemantik($slotLabel, $hgLabel);
    }

    /**
     * Ranking: Slot-Semantik (HG passt zum Slot-Label) → Kanten-Gewinn zur bisherigen
     * Menüfolge (Pairing-Graph) → Anker-Anzahl (graph-erreichbare Gerichte zuerst) →
     * Nähe zum Preis-Anker → Name (stabil).
     */
    private function besterKandidat(Collection $kandidaten, array $gewaehlteAnker, $frameSlot, ?string $zielNiveau = null, ?string $zielConvenience = null): ?array
    {
        if ($kandidaten->isEmpty()) {
            return null;
        }
        $kanten = $gewaehlteAnker !== []
            ? $this->pairing->edgesFor(array_unique(array_merge($gewaehlteAnker, $kandidaten->flatMap(fn ($k) => $k['anker'])->unique()->values()->all())))
            : [];
        // Semantik EINMAL je Kandidat über die geteilte Naht (12·S3a) statt zweimal inline.
        // Das Gate „nur anwenden, wenn überhaupt einer passt" bleibt stehen wie im Bestand;
        // es ist beweisbar folgenlos (ohne Treffer ist jeder Wert ohnehin 0) und wird
        // deshalb hier nicht angetastet, sondern gemeldet (→ V-066).
        $semantik = MenuCandidatePoolService::semantikJeKandidat($kandidaten, $frameSlot);
        $hatSemantik = in_array(1, $semantik, true);

        return $kandidaten->map(function ($k) use ($kanten, $gewaehlteAnker, $frameSlot, $hatSemantik, $semantik, $zielNiveau, $zielConvenience) {
            $k['semantik'] = $hatSemantik ? ($semantik[(int) $k['id']] ?? 0) : 0;
            // Phase 5: Segment-Niveau bevorzugen (neutral, wenn kein Ziel-Niveau übergeben wird).
            $k['niveau_match'] = ($zielNiveau !== null && in_array($zielNiveau, $k['niveaus'] ?? [], true)) ? 1 : 0;
            // Convenience-Leitplanke: Anteil convenience-getaggter GPs unter den Zutaten (0..1).
            // from_scratch → scratch bevorzugen (1-ratio), voll_convenience → Convenience bevorzugen (ratio),
            // teil_convenience/null → neutral (0, Mix erlaubt). ratio null (nicht geladen) = neutral.
            $ratio = $k['convenience_ratio'] ?? null;
            $k['convenience_match'] = match ($zielConvenience) {
                'from_scratch' => $ratio === null ? 0.0 : 1.0 - $ratio,
                'voll_convenience' => $ratio ?? 0.0,
                default => 0.0,
            };
            $gewinn = 0.0;
            $paare = 0;
            foreach ($k['anker'] as $a) {
                foreach ($gewaehlteAnker as $b) {
                    if ($a === $b) {
                        $gewinn += 1.0;
                        $paare++;
                    } elseif (isset($kanten[$a][$b])) {
                        $gewinn += $kanten[$a][$b][0];
                        $paare++;
                    }
                }
            }
            $k['score'] = $paare > 0 ? $gewinn / $paare : 0.0;
            $k['ankerdichte'] = count($k['anker']);
            $k['preisnaehe'] = $frameSlot->price_anchor !== null && $k['sales_net'] !== null
                ? -abs($k['sales_net'] - (float) $frameSlot->price_anchor)
                : 0.0;

            return $k;
        })->sortBy([['semantik', 'desc'], ['niveau_match', 'desc'], ['convenience_match', 'desc'], ['score', 'desc'], ['ankerdichte', 'desc'], ['preisnaehe', 'desc'], ['name', 'asc']])->first();
    }

    /**
     * KI-Gerüst-Werte defensiv säubern: nur bekannte Felder/rule_types/Diät-Keys
     * überleben — eine kaputte KI-Regel darf nicht das ganze Gerüst (Transaktion)
     * reißen. Unbekanntes wird verworfen, nicht geraten.
     *
     * @return array{0: list<array>, 1: list<array>}
     */
    private function sanitizeGeruestWerte(array $slots, array $rules): array
    {
        $regelSaeubern = function ($r): ?array {
            if (! is_array($r) || ! in_array($r['rule_type'] ?? null, \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule::RULE_TYPES, true)) {
                return null;
            }
            if ($r['rule_type'] === 'diet_quota' && ! in_array($r['ref_key'] ?? null, \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule::DIET_FORMS, true)) {
                return null;
            }
            if ($r['rule_type'] === 'nogo_allergen' && ! in_array($r['ref_key'] ?? null, FoodAlchemistGp::ALLERGEN_FIELDS, true)) {
                return null;
            }

            return [
                'rule_type' => $r['rule_type'],
                'ref_key' => isset($r['ref_key']) && is_string($r['ref_key']) ? $r['ref_key'] : null,
                'ref_id' => is_numeric($r['ref_id'] ?? null) ? (int) $r['ref_id'] : null,
                'operator' => in_array($r['operator'] ?? null, \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule::OPERATORS, true) ? $r['operator'] : 'min',
                'value_num' => is_numeric($r['value_num'] ?? null) ? (float) $r['value_num'] : null,
                'unit' => in_array($r['unit'] ?? null, \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule::UNITS, true) ? $r['unit'] : null,
                'value_text' => isset($r['value_text']) && is_string($r['value_text']) ? mb_substr($r['value_text'], 0, 500) : null,
                'severity' => in_array($r['severity'] ?? null, ['hart', 'weich'], true) ? $r['severity'] : null,
            ];
        };

        $sichereSlots = [];
        foreach ($slots as $s) {
            if (! is_array($s) || trim((string) ($s['label'] ?? '')) === '') {
                continue;
            }
            $sichereSlots[] = [
                'label' => mb_substr(trim((string) $s['label']), 0, 190),
                'slot_type' => in_array($s['slot_type'] ?? null, \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot::SLOT_TYPES, true) ? $s['slot_type'] : null,
                'target_count' => is_numeric($s['target_count'] ?? null) ? max(1, (int) $s['target_count']) : null,
                'price_anchor' => is_numeric($s['price_anchor'] ?? null) ? (float) $s['price_anchor'] : null,
                'price_min' => is_numeric($s['price_min'] ?? null) ? (float) $s['price_min'] : null,
                'price_max' => is_numeric($s['price_max'] ?? null) ? (float) $s['price_max'] : null,
                'is_pflicht' => (bool) ($s['is_pflicht'] ?? false),
                'rules' => array_values(array_filter(array_map($regelSaeubern, is_array($s['rules'] ?? null) ? $s['rules'] : []))),
            ];
        }

        return [$sichereSlots, array_values(array_filter(array_map($regelSaeubern, $rules)))];
    }
}
