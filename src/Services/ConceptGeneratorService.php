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
    public function generiereAusBrief(Team $team, string $brief, ?string $name = null, string $via = 'ui', bool $useFavoritesList = false, bool $favoritesConvenienceOnly = false): array
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

        $proposal = app(AiGatewayService::class)->propose('concept.brief_geruest', $kontext);
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
        [$sichereSlots, $sichereRules] = $this->sanitizeGeruestWerte($slots, is_array($werte['rules'] ?? null) ? $werte['rules'] : []);
        if ($sichereSlots === []) {
            throw new RuntimeException('KI-Gerüst enthielt keine gültigen Slots — Brief präzisieren.');
        }
        $this->frames->replaceStructure($team, $frame, $sichereSlots, $sichereRules);

        // Assembler auf dem frischen Gerüst — Slots des leeren Konzepts füllen
        $ergebnis = $this->fuelleBestehendesKonzept($team, $concept, $frame->refresh());

        return $ergebnis + ['brief_confidence' => $proposal->confidence ?? null];
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
     * @param array<string,mixed> $extraKontext segment · marken_kontext · anlaesse …
     * @return array{frame: FoodAlchemistPlanningFrame, confidence: float|null, slots: int, name: ?string}
     */
    public function geruestAusBriefFuerOwner(Team $team, string $ownerType, int $ownerId, string $brief, array $extraKontext = [], string $via = 'ui'): array
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

        $proposal = app(AiGatewayService::class)->propose('concept.brief_geruest', $kontext);
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
        [$sichereSlots, $sichereRules] = $this->sanitizeGeruestWerte($slots, is_array($werte['rules'] ?? null) ? $werte['rules'] : []);
        if ($sichereSlots === []) {
            throw new RuntimeException('KI-Gerüst enthielt keine gültigen Slots — Brief präzisieren.');
        }
        $this->frames->replaceStructure($team, $frame, $sichereSlots, $sichereRules);

        return [
            'frame' => $frame->refresh(),
            'confidence' => $proposal->confidence ?? null,
            'slots' => count($sichereSlots),
            'name' => is_string($werte['name'] ?? null) && trim($werte['name']) !== '' ? trim($werte['name']) : null,
        ];
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
     * Deterministischer Token-Präfix-Vergleich („Hauptgang" ↔ „Hauptgericht" via
     * gemeinsamem Präfix ≥5) — kein Match bei freien Labels (Boost neutral 0).
     */
    public static function slotSemantik(string $slotLabel, string $hgLabel): int
    {
        if ($hgLabel === '') {
            return 0;
        }
        $slotTokens = preg_split('/[^a-zäöüß]+/u', mb_strtolower($slotLabel), -1, PREG_SPLIT_NO_EMPTY);
        $hgTokens = preg_split('/[^a-zäöüß]+/u', $hgLabel, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($slotTokens as $s) {
            foreach ($hgTokens as $h) {
                $len = min(mb_strlen($s), mb_strlen($h));
                if ($len >= 5 && mb_substr($s, 0, 5) === mb_substr($h, 0, 5)) {
                    return 1;
                }
                if ($s === $h && $len >= 3) {
                    return 1;
                }
            }
        }

        return 0;
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
        // Semantik nur anwenden, wenn ÜBERHAUPT ein Kandidat zum Slot-Label passt —
        // sonst würde ein freies Label („Station Süß") nichts filtern, aber auch nichts kaputt machen.
        $hatSemantik = $kandidaten->contains(fn ($k) => self::slotSemantik((string) $frameSlot->label, $k['hg_label']) === 1);

        return $kandidaten->map(function ($k) use ($kanten, $gewaehlteAnker, $frameSlot, $hatSemantik, $zielNiveau, $zielConvenience) {
            $k['semantik'] = $hatSemantik ? self::slotSemantik((string) $frameSlot->label, $k['hg_label']) : 0;
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
