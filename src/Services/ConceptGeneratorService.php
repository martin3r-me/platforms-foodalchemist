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
        $pool = $this->kandidatenPool($team, $frame);

        $protokoll = [];
        $gewaehlt = collect();
        $gewaehlteAnker = [];
        foreach ($frame->slots as $frameSlot) {
            $n = max(1, (int) ($frameSlot->target_count ?? 1));
            $kandidaten = $this->filterFuerSlot($pool, $frame, $frameSlot)->reject(fn ($k) => $gewaehlt->has($k['id']));
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
                $begruendung = 'Kein VK-Gericht erfüllt die Vorgaben (' . $this->filterBeschreibung($frame, $frameSlot) . ') — Slot bewusst leer gelassen.';
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
                'begruendung' => $fehlend > 0 ? "{$fehlend} von {$n} Plätzen unbefüllbar (" . $this->filterBeschreibung($frame, $frameSlot) . ')' : null,
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
    public function slotVorschlaege(Team $team, FoodAlchemistPlanningFrame $frame, FoodAlchemistPlanningFrameSlot $slot, int $limit = 6, ?string $zielNiveau = null): array
    {
        $frame->loadMissing(['slots.rules', 'rules']);
        $kandidaten = $this->filterFuerSlot($this->kandidatenPool($team, $frame), $frame, $slot);

        $out = [];
        $gewaehlteAnker = [];
        $gewaehltIds = [];
        while (count($out) < max(1, $limit)) {
            $rest = $kandidaten->reject(fn ($k) => in_array($k['id'], $gewaehltIds, true));
            $treffer = $this->besterKandidat($rest, $gewaehlteAnker, $slot, $zielNiveau);
            if ($treffer === null) {
                break;
            }
            $out[] = ['id' => (int) $treffer['id'], 'name' => (string) $treffer['name'], 'diet_form' => $treffer['diet_form'], 'sales_net' => $treffer['sales_net']];
            $gewaehltIds[] = $treffer['id'];
            $gewaehlteAnker = array_unique(array_merge($gewaehlteAnker, $treffer['anker']));
        }

        return $out;
    }

    // ── Kandidaten ──────────────────────────────────────────────────────

    /**
     * Pool echter VK-Gerichte (keine Drafts, keine Slot-Varianten) mit allem, was
     * Filter + Ranking brauchen: diet_form, Preis, Allergen-Werte, Begriffs-Korpus
     * (nur wenn No-Go-Zutat-Regeln existieren), Anker-IDs (persistiertes Mapping +
     * dynamische Auflösung über die Zutaten).
     */
    private function kandidatenPool(Team $team, FoodAlchemistPlanningFrame $frame): Collection
    {
        $brauchtBegriffe = $frame->rules->where('rule_type', 'nogo_ingredient')->isNotEmpty();

        $query = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->where('status', '!=', 'draft')
            // Modell A: HG hängt direkt am Recipe (dish_main_group_id); dishClass.mainGroup = Alt-Pfad-Fallback
            // levelSuitabilities = Niveau-Eignungen (haute_cuisine|gehoben|klassisch) fürs Segment-Ranking (Phase 5)
            ->with(['dishClass:id,diet_form,dish_main_group_id', 'dishClass.mainGroup:id,code,label', 'speisenHauptgruppe:id,code,label', 'levelSuitabilities']);
        if ($brauchtBegriffe) {
            $query->with(['ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name']);
        }

        return $query->get()->map(function (FoodAlchemistRecipe $r) use ($brauchtBegriffe) {
            $allergene = [];
            foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $key) {
                $allergene[$key] = $r->{'allergen_' . $key} ?? null;
            }
            $diet = $r->dishClass?->diet_form;
            if ($diet === null || $diet === 'neutral') {
                if ($r->spec_is_vegan === true) {
                    $diet = 'vegan';
                } elseif ($r->spec_is_vegetarian === true) {
                    $diet = $diet ?? 'vegi';
                }
            }

            return [
                'id' => $r->id,
                'name' => $r->name,
                'diet_form' => $diet,
                'hg_label' => mb_strtolower(trim((string) ($r->speisenHauptgruppe?->label ?? $r->dishClass?->mainGroup?->label ?? ''))),
                'sales_net' => $r->sales_net !== null ? (float) $r->sales_net : null,
                'allergene' => $allergene,
                'begriffe' => $brauchtBegriffe
                    ? mb_strtolower($r->name . ' ' . $r->ingredients->map(fn ($z) => ($z->gp?->name ?? '') . ' ' . ($z->referencedRecipe?->name ?? ''))->implode(' '))
                    : mb_strtolower($r->name),
                'niveaus' => $r->levelSuitabilities->pluck('level_slug')->filter()->values()->all(),
                'anker' => $this->pairing->anchorsForRecipe($r),
            ];
        })->keyBy('id');
    }

    /** Harte Filter eines Slots: No-Gos (frame + slot, hart), Allergen-No-Gos, Preisrahmen. */
    private function filterFuerSlot(Collection $pool, FoodAlchemistPlanningFrame $frame, $frameSlot): Collection
    {
        $regeln = $frame->rules->whereNull('slot_id')->merge($frameSlot->rules);
        $nogoTerms = $regeln->where('rule_type', 'nogo_ingredient')
            ->map(fn ($r) => mb_strtolower(trim((string) $r->value_text)))->filter()->values();
        $nogoAllergene = $regeln->where('rule_type', 'nogo_allergen')->pluck('ref_key')->filter()->values();

        return $pool->filter(function ($k) use ($nogoTerms, $nogoAllergene, $frameSlot) {
            foreach ($nogoTerms as $term) {
                if (str_contains($k['begriffe'], $term)) {
                    return false; // No-Gos wirken HART im Generator — nie vorschlagen
                }
            }
            foreach ($nogoAllergene as $key) {
                if (in_array($k['allergene'][$key] ?? null, ['enthalten', 'spuren'], true)) {
                    return false;
                }
            }
            if ($frameSlot->price_min !== null && ($k['sales_net'] === null || $k['sales_net'] < (float) $frameSlot->price_min)) {
                return false;
            }
            if ($frameSlot->price_max !== null && ($k['sales_net'] === null || $k['sales_net'] > (float) $frameSlot->price_max)) {
                return false;
            }

            return true;
        });
    }

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
    private function besterKandidat(Collection $kandidaten, array $gewaehlteAnker, $frameSlot, ?string $zielNiveau = null): ?array
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

        return $kandidaten->map(function ($k) use ($kanten, $gewaehlteAnker, $frameSlot, $hatSemantik, $zielNiveau) {
            $k['semantik'] = $hatSemantik ? self::slotSemantik((string) $frameSlot->label, $k['hg_label']) : 0;
            // Phase 5: Segment-Niveau bevorzugen (neutral, wenn kein Ziel-Niveau übergeben wird).
            $k['niveau_match'] = ($zielNiveau !== null && in_array($zielNiveau, $k['niveaus'] ?? [], true)) ? 1 : 0;
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
        })->sortBy([['semantik', 'desc'], ['niveau_match', 'desc'], ['score', 'desc'], ['ankerdichte', 'desc'], ['preisnaehe', 'desc'], ['name', 'asc']])->first();
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

    /** Menschlich lesbare Filter-Zusammenfassung für Leer-Begründungen. */
    private function filterBeschreibung(FoodAlchemistPlanningFrame $frame, $frameSlot): string
    {
        $teile = [];
        $regeln = $frame->rules->whereNull('slot_id')->merge($frameSlot->rules);
        $nogos = $regeln->where('rule_type', 'nogo_ingredient')->pluck('value_text')->filter()->all();
        if ($nogos !== []) {
            $teile[] = 'No-Go: ' . implode(', ', $nogos);
        }
        $allergene = $regeln->where('rule_type', 'nogo_allergen')->pluck('ref_key')->filter()->all();
        if ($allergene !== []) {
            $teile[] = 'ohne Allergen: ' . implode(', ', $allergene);
        }
        $quoten = $regeln->where('rule_type', 'diet_quota')->map(fn ($r) => $r->operator . ' ' . $r->value_num . ' ' . ($r->unit === 'percent' ? '%' : '×') . ' ' . $r->ref_key)->all();
        if ($quoten !== []) {
            $teile[] = 'Diät: ' . implode('; ', $quoten);
        }
        if ($frameSlot->price_min !== null || $frameSlot->price_max !== null) {
            $teile[] = 'Preisrahmen ' . ($frameSlot->price_min ?? '—') . '–' . ($frameSlot->price_max ?? '—') . ' €';
        }

        return $teile !== [] ? implode(' · ', $teile) : 'keine Regeln, aber kein Gericht im Bestand';
    }
}
